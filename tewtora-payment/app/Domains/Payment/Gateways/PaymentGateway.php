<?php

namespace App\Domains\Payment\Gateways;

use App\Domains\Payment\Exceptions\PaymentProviderException;

/**
 * Thin provider adapter (docs/microservices-architecture.md §7.5): one
 * implementation per provider, no branching on provider code elsewhere.
 */
interface PaymentGateway
{
    /** The payment_providers.code this adapter handles. */
    public function code(): string;

    /** @throws PaymentProviderException */
    public function initialize(
        string $reference,
        string $email,
        int $amountMinor,
        string $currencyCode,
        string $callbackUrl,
        array $metadata = [],
    ): InitializedTransaction;

    /** @throws PaymentProviderException */
    public function verify(string $reference): VerifiedTransaction;

    /** Constant-time check that a webhook really came from the provider. */
    public function hasValidSignature(string $rawBody, ?string $signature): bool;
}
