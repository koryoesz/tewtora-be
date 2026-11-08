<?php

namespace App\Domains\Payment\Services;

use App\Domains\Payment\Exceptions\PaymentProviderException;
use App\Domains\Payment\Gateways\PaymentGateway;
use App\Domains\Payment\Gateways\VerifiedTransaction;
use App\Domains\Payment\Models\OutboxEvent;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\PaymentProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Provider checkouts (docs/microservices-architecture.md §7.3):
 *
 * start()  — one payments row per (purpose, purpose_ref), so the caller can
 *            retry freely; the row's public_id is the Paystack reference.
 * refresh()— asks the provider about a payment still `processing` (the
 *            payer's "I've paid" check, and the reconciliation safety net
 *            for a missed webhook, §7.4).
 * settle() — the one place a payment reaches a final status, from either a
 *            signed webhook or a verify call. Only ever moves a row out of
 *            `processing`, so a repeated webhook is a no-op. Writes
 *            PaymentSucceeded/PaymentFailed to the outbox in the same
 *            transaction (CLAUDE.md "Events & the outbox").
 */
class CheckoutService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * @param  array{purpose: string, purpose_ref: string, payer_account_id: int, email: string, amount_minor: int, currency_code: string, callback_url: string, description?: ?string}  $data
     *
     * @throws PaymentProviderException
     */
    public function start(array $data): Payment
    {
        $existing = $this->findByPurpose($data['purpose'], $data['purpose_ref']);

        if ($existing !== null) {
            return $existing;
        }

        try {
            // public_id is DB-generated (DEFAULT (UUID())) — create()'s
            // in-memory model doesn't know it without a refresh.
            $payment = Payment::create([
                'payer_account_id' => $data['payer_account_id'],
                'purpose' => $data['purpose'],
                'purpose_ref' => $data['purpose_ref'],
                'provider_id' => $this->providerId(),
                'amount_minor' => $data['amount_minor'],
                'currency_code' => $data['currency_code'],
                'status' => 'processing',
            ])->refresh();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request for the same purpose won the race.
            return $this->findByPurpose($data['purpose'], $data['purpose_ref']);
        }

        try {
            $transaction = $this->gateway->initialize(
                reference: $payment->public_id,
                email: $data['email'],
                amountMinor: $payment->amount_minor,
                currencyCode: $payment->currency_code,
                callbackUrl: $data['callback_url'],
                metadata: [
                    'purpose' => $payment->purpose,
                    'purpose_ref' => $payment->purpose_ref,
                    'description' => $data['description'] ?? null,
                ],
            );
        } catch (PaymentProviderException $e) {
            // Nothing was charged; a retry from the caller uses a new purpose_ref.
            $payment->update(['status' => 'failed', 'failure_reason' => 'initialize failed']);

            throw $e;
        }

        $payment->update([
            'provider_reference' => $transaction->reference,
            'checkout_url' => $transaction->checkoutUrl,
        ]);

        Log::channel('payment')->info('checkout started', ['payment_id' => $payment->public_id, 'purpose' => $payment->purpose]);

        return $payment;
    }

    /** @throws PaymentProviderException */
    public function refresh(Payment $payment): Payment
    {
        if ($payment->status !== 'processing' || $payment->provider_reference === null) {
            return $payment;
        }

        return $this->settle($payment, $this->gateway->verify($payment->provider_reference));
    }

    public function settle(Payment $payment, VerifiedTransaction $transaction): Payment
    {
        if ($transaction->status === VerifiedTransaction::PENDING) {
            return $payment;
        }

        return DB::transaction(function () use ($payment, $transaction) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== 'processing') {
                return $payment; // already settled — duplicate webhook or verify
            }

            if ($transaction->status === VerifiedTransaction::SUCCESS
                && ($transaction->amountMinor !== $payment->amount_minor || $transaction->currencyCode !== $payment->currency_code)) {
                // Should be impossible (we set the amount); never settle on it. Needs a human.
                Log::channel('payment')->critical('provider amount mismatch', [
                    'payment_id' => $payment->public_id,
                    'expected' => [$payment->amount_minor, $payment->currency_code],
                    'received' => [$transaction->amountMinor, $transaction->currencyCode],
                ]);

                return $payment;
            }

            $succeeded = $transaction->status === VerifiedTransaction::SUCCESS;

            $payment->update($succeeded
                ? ['status' => 'success', 'paid_at' => $transaction->paidAt ?? now(), 'channel' => $transaction->channel]
                : ['status' => 'failed', 'failure_reason' => mb_substr((string) $transaction->message, 0, 255) ?: 'declined']);

            OutboxEvent::create([
                'aggregate_type' => 'payment',
                'aggregate_id' => $payment->id,
                'event_type' => $succeeded ? 'PaymentSucceeded' : 'PaymentFailed',
                'payload' => [
                    'payment_id' => $payment->public_id,
                    'purpose' => $payment->purpose,
                    'purpose_ref' => $payment->purpose_ref,
                    'amount_minor' => $payment->amount_minor,
                    'currency_code' => $payment->currency_code,
                    'provider_reference' => $payment->provider_reference,
                    'paid_at' => $payment->paid_at?->toIso8601ZuluString(),
                ],
            ]);

            Log::channel('payment')->info($succeeded ? 'payment succeeded' : 'payment failed', ['payment_id' => $payment->public_id]);

            return $payment;
        });
    }

    public function findByProviderReference(string $reference): ?Payment
    {
        return Payment::where('provider_id', $this->providerId())->where('provider_reference', $reference)->first();
    }

    private function findByPurpose(string $purpose, string $purposeRef): ?Payment
    {
        return Payment::where('purpose', $purpose)->where('purpose_ref', $purposeRef)->first();
    }

    private function providerId(): int
    {
        return (int) PaymentProvider::where('code', $this->gateway->code())->where('is_active', true)->valueOrFail('id');
    }
}
