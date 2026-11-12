<?php

namespace App\Domains\Core\Services\Payments;

use App\Domains\Core\Exceptions\PaymentServiceUnavailableException;

/**
 * Core's side of the sync Main -> Payment call (microservices-architecture.md
 * §3: "user is on a payment screen waiting for a result"). Payment owns the
 * provider (Paystack) integration; Core only ever sees a checkout URL and
 * a status, never provider credentials.
 */
interface PaymentServiceClient
{
    /**
     * Idempotent on $purposeRef: asking twice for the same enrolment returns
     * the same checkout, never a second charge.
     *
     * @throws PaymentServiceUnavailableException
     */
    public function startCheckout(
        string $purpose,
        string $purposeRef,
        int $payerAccountId,
        string $payerEmail,
        int $amountMinor,
        string $currencyCode,
        string $callbackUrl,
        string $description,
    ): CheckoutSession;

    /**
     * Current status, re-verified with the provider when Payment hasn't
     * heard a final outcome yet.
     *
     * @throws PaymentServiceUnavailableException
     */
    public function status(string $paymentId): PaymentStatus;
}
