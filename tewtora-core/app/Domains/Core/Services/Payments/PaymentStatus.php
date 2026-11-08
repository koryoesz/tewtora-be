<?php

namespace App\Domains\Core\Services\Payments;

final readonly class PaymentStatus
{
    public function __construct(
        public string $paymentId,
        /** processing | success | failed */
        public string $status,
        public int $amountMinor,
        public string $currencyCode,
        public ?string $providerReference,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 'success';
    }
}
