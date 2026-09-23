<?php

namespace Tests\Concerns;

use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\PaymentMethod;
use App\Domains\Payment\Models\PaymentProvider;

/**
 * Minimal fixture builders for the Payment graph — a `payments` row needs
 * a payment_method and a provider to exist first. Plain Model::create()
 * calls rather than factories, matching tewtora-core's test fixtures.
 */
trait SeedsPaymentGraph
{
    protected function makePaymentMethod(array $overrides = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'code' => 'method-'.uniqid(),
            'display_name' => 'Test Method',
        ], $overrides));
    }

    protected function makePaymentProvider(array $overrides = []): PaymentProvider
    {
        return PaymentProvider::create(array_merge([
            'code' => 'provider-'.uniqid(),
            'display_name' => 'Test Provider',
            'webhook_secret_ref' => 'vault://test/webhook-secret',
        ], $overrides));
    }

    protected function makePayment(array $overrides = []): Payment
    {
        $method = $overrides['payment_method_id'] ?? $this->makePaymentMethod()->id;
        $provider = $overrides['provider_id'] ?? $this->makePaymentProvider()->id;

        return Payment::create(array_merge([
            'payer_account_id' => 1,
            'payment_method_id' => $method,
            'provider_id' => $provider,
            'plan_type' => 'per_session',
            'sessions_covered' => 1,
            'amount_minor' => 500000,
            'status' => 'success',
        ], $overrides));
    }
}
