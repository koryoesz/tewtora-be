<?php

namespace App\Domains\Core\Services\Payments;

final readonly class CheckoutSession
{
    public function __construct(
        public string $paymentId,
        public string $checkoutUrl,
        public ?string $providerReference,
    ) {}
}
