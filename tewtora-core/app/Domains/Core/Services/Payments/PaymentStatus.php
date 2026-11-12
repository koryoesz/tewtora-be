<?php

namespace App\Domains\Core\Services\Payments;

use Carbon\CarbonImmutable;

final readonly class PaymentStatus
{
    public function __construct(
        public string $paymentId,
        /** processing | success | failed (failed isn't final: a retry can still succeed) */
        public string $status,
        public int $amountMinor,
        public string $currencyCode,
        public ?string $providerReference,
        /** card | bank_transfer | ... — null until paid, or if Payment doesn't know */
        public ?string $channel = null,
        public ?CarbonImmutable $paidAt = null,
        /** What Payment says this payment was for — checked before it confirms anything. */
        public ?string $purpose = null,
        public ?string $purposeRef = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 'success';
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }
}
