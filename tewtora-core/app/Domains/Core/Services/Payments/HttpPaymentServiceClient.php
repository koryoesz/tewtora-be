<?php

namespace App\Domains\Core\Services\Payments;

use App\Domains\Core\Exceptions\PaymentServiceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Calls tewtora-payment's internal API (`/api/v1/internal/checkouts`),
 * authenticated with the shared INTERNAL_SERVICE_TOKEN and carrying the
 * caller's X-Request-Id so one request can be traced across both services
 * (backend-engineering-standards.md §7).
 */
class HttpPaymentServiceClient implements PaymentServiceClient
{
    public function startCheckout(
        string $purpose,
        string $purposeRef,
        int $payerAccountId,
        string $payerEmail,
        int $amountMinor,
        string $currencyCode,
        string $callbackUrl,
        string $description,
    ): CheckoutSession {
        $data = $this->send(fn (PendingRequest $http) => $http->post('/internal/checkouts', [
            'purpose' => $purpose,
            'purpose_ref' => $purposeRef,
            'payer_account_id' => $payerAccountId,
            'email' => $payerEmail,
            'amount_minor' => $amountMinor,
            'currency_code' => $currencyCode,
            'callback_url' => $callbackUrl,
            'description' => $description,
        ]));

        if (empty($data['payment_id']) || empty($data['checkout_url'])) {
            throw new PaymentServiceUnavailableException('Payment service returned no checkout URL.');
        }

        return new CheckoutSession($data['payment_id'], $data['checkout_url'], $data['provider_reference'] ?? null);
    }

    public function status(string $paymentId): PaymentStatus
    {
        $data = $this->send(fn (PendingRequest $http) => $http->get('/internal/checkouts/'.rawurlencode($paymentId)));

        return new PaymentStatus(
            $data['payment_id'],
            $data['status'],
            (int) $data['amount_minor'],
            $data['currency_code'],
            $data['provider_reference'] ?? null,
        );
    }

    /** @return array<string, mixed> the response's `data` */
    private function send(callable $call): array
    {
        $http = Http::baseUrl(rtrim((string) config('services.payment.url'), '/').'/api/v1')
            ->withHeaders([
                'X-Internal-Token' => (string) config('services.payment.internal_token'),
                'X-Request-Id' => (string) request()->attributes->get('request_id', ''),
            ])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(15)
            // Retry only when the request never reached Payment; a timed-out
            // POST is safe to resend anyway, since checkouts are idempotent
            // on purpose_ref.
            ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false);

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            throw new PaymentServiceUnavailableException('Payment service unreachable.', $e);
        }

        if ($response->failed()) {
            throw new PaymentServiceUnavailableException(
                "Payment service responded {$response->status()}: ".($response->json('error.code') ?? 'unknown'),
                $response->toException(),
            );
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new PaymentServiceUnavailableException('Payment service returned an unreadable response.');
        }

        return $data;
    }
}
