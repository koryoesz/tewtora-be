<?php

namespace App\Domains\Payment\Services;

use App\Domains\Payment\Exceptions\PaymentProviderException;
use App\Domains\Payment\Gateways\PaymentGateway;
use App\Domains\Payment\Gateways\VerifiedTransaction;
use App\Domains\Payment\Models\OutboxEvent;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\PaymentProvider;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Provider checkouts (docs/microservices-architecture.md §7.3):
 *
 * start()  — one payments row per (purpose, purpose_ref), so the caller can
 *            retry freely; the row's public_id is the Paystack reference.
 * refresh()— asks the provider about a payment that isn't `success` yet (the
 *            payer's "I've paid" check, and reconcile()'s safety net for a
 *            missed webhook, §7.4).
 * settle() — the one place a payment's status changes, from either a signed
 *            webhook or a verify call. Writes PaymentSucceeded/PaymentFailed
 *            to the outbox in the same transaction (CLAUDE.md "Events & the
 *            outbox"). Transitions:
 *              processing -> success | failed
 *              failed     -> success   (a declined card, then a retry on the
 *                                       same Paystack page that went through)
 *            `success` is final. Anything else (a repeated webhook, a stale
 *            failure after a success) is a no-op, so redelivery is safe.
 *            Money taken must always reach Core, which is why a failure is
 *            not treated as final.
 */
class CheckoutService
{
    /** failure_reason marking a payment that needs a human, not another verify. */
    public const AMOUNT_MISMATCH = 'amount_mismatch';

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
        if (! $this->isOpenAtProvider($payment)) {
            return $payment;
        }

        return $this->settle($payment, $this->gateway->verify($payment->provider_reference));
    }

    /**
     * §7.4 safety net, run on a schedule: re-verifies recent payments that
     * the provider may have settled without us hearing (a missed webhook,
     * a payer who never came back). Oldest first, at most $limit per run to
     * stay well inside the provider's rate limits. One provider error never
     * stops the run.
     *
     * @return array{checked: int, settled: int, errors: int}
     */
    public function reconcile(CarbonInterface $createdFrom, CarbonInterface $createdUntil, int $limit): array
    {
        $result = ['checked' => 0, 'settled' => 0, 'errors' => 0];

        $candidates = Payment::where('provider_id', $this->providerId())
            ->whereIn('status', ['processing', 'failed'])
            ->whereNotNull('provider_reference')
            ->whereNotNull('checkout_url')
            ->where(fn ($q) => $q->whereNull('failure_reason')->orWhere('failure_reason', '!=', self::AMOUNT_MISMATCH))
            ->whereBetween('created_at', [$createdFrom, $createdUntil])
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($candidates as $payment) {
            $result['checked']++;
            $before = $payment->status;

            try {
                if ($this->refresh($payment)->status !== $before) {
                    $result['settled']++;
                }
            } catch (PaymentProviderException $e) {
                $result['errors']++;
                Log::channel('payment')->warning('reconcile: provider verify failed', ['payment_id' => $payment->public_id] + $e->context());
            }
        }

        if ($result['checked'] > 0) {
            Log::channel('payment')->info('reconcile run', $result);
        }

        return $result;
    }

    public function settle(Payment $payment, VerifiedTransaction $transaction): Payment
    {
        if ($transaction->status === VerifiedTransaction::PENDING) {
            return $payment;
        }

        return DB::transaction(function () use ($payment, $transaction) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $succeeded = $transaction->status === VerifiedTransaction::SUCCESS;

            if ($payment->status === 'success' || (! $succeeded && $payment->status === 'failed')) {
                return $payment; // already settled — duplicate webhook/verify, or a stale failure
            }

            if ($succeeded && ($transaction->amountMinor !== $payment->amount_minor || $transaction->currencyCode !== $payment->currency_code)) {
                // Should be impossible (we set the amount); never settle on it. Needs a human.
                // Flagged so reconcile() stops re-verifying it every run.
                Log::channel('payment')->critical('provider amount mismatch', [
                    'payment_id' => $payment->public_id,
                    'expected' => [$payment->amount_minor, $payment->currency_code],
                    'received' => [$transaction->amountMinor, $transaction->currencyCode],
                ]);
                $payment->update(['failure_reason' => self::AMOUNT_MISMATCH]);

                return $payment;
            }

            if ($succeeded && $payment->status === 'failed') {
                Log::channel('payment')->warning('payment succeeded after an earlier failure', ['payment_id' => $payment->public_id]);
            }

            $payment->update($succeeded
                ? ['status' => 'success', 'paid_at' => $transaction->paidAt ?? now(), 'channel' => $transaction->channel, 'failure_reason' => null]
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
                    'channel' => $payment->channel,
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

    /** A real provider transaction (initialize succeeded) whose outcome could still change. */
    private function isOpenAtProvider(Payment $payment): bool
    {
        return in_array($payment->status, ['processing', 'failed'], true)
            && $payment->provider_reference !== null
            && $payment->checkout_url !== null
            && $payment->failure_reason !== self::AMOUNT_MISMATCH;
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
