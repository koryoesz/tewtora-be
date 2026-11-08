<?php

namespace App\Domains\Payment\Gateways;

use App\Domains\Payment\Exceptions\PaymentProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Paystack (https://paystack.com/docs/api/transaction/). Amounts are in the
 * currency's subunit (kobo for NGN), the same as our amount_minor, so no
 * conversion. Paystack's own statuses map to ours: success -> success,
 * failed/reversed -> failed, anything else (abandoned, ongoing, pending,
 * processing, queued) -> pending, since the payer can still finish.
 */
class PaystackGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'paystack';
    }

    public function initialize(
        string $reference,
        string $email,
        int $amountMinor,
        string $currencyCode,
        string $callbackUrl,
        array $metadata = [],
    ): InitializedTransaction {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/transaction/initialize', [
            'reference' => $reference,
            'email' => $email,
            'amount' => $amountMinor,
            'currency' => $currencyCode,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]));

        $url = $response->json('data.authorization_url');

        if (! is_string($url) || $url === '') {
            throw new PaymentProviderException('paystack', 'initialize returned no authorization_url');
        }

        return new InitializedTransaction($response->json('data.reference') ?? $reference, $url);
    }

    public function verify(string $reference): VerifiedTransaction
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/transaction/verify/'.rawurlencode($reference)));

        return $this->toVerified((array) $response->json('data'));
    }

    public function hasValidSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.paystack.secret_key');

        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }

    /** Also used for a signed charge.success webhook's `data` object, which has the same shape. */
    public function toVerified(array $data): VerifiedTransaction
    {
        $status = match ($data['status'] ?? null) {
            'success' => VerifiedTransaction::SUCCESS,
            'failed', 'reversed' => VerifiedTransaction::FAILED,
            default => VerifiedTransaction::PENDING,
        };

        return new VerifiedTransaction(
            reference: (string) ($data['reference'] ?? ''),
            status: $status,
            amountMinor: (int) ($data['amount'] ?? 0),
            currencyCode: strtoupper((string) ($data['currency'] ?? '')),
            channel: $data['channel'] ?? null,
            paidAt: ! empty($data['paid_at']) ? CarbonImmutable::parse($data['paid_at']) : null,
            message: $data['gateway_response'] ?? null,
        );
    }

    private function send(callable $call): Response
    {
        $secret = (string) config('services.paystack.secret_key');

        if ($secret === '') {
            throw new PaymentProviderException('paystack', 'PAYSTACK_SECRET_KEY is not set');
        }

        $http = Http::baseUrl(rtrim((string) config('services.paystack.base_url'), '/'))
            ->withToken($secret)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false);

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            throw new PaymentProviderException('paystack', 'unreachable: '.$e->getMessage(), $e);
        }

        if ($response->failed() || $response->json('status') !== true) {
            throw new PaymentProviderException('paystack', "HTTP {$response->status()}: ".($response->json('message') ?? 'no message'));
        }

        return $response;
    }
}
