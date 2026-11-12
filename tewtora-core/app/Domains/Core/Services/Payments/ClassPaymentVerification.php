<?php

namespace App\Domains\Core\Services\Payments;

use App\Domains\Core\Models\ClassEnrollment;

/**
 * What verifying a class payment found: the payment's state, and whether
 * the learner now actually holds a seat. Those differ when money landed
 * for a class that has since been cancelled (paid, not enrolled), so the
 * frontend must branch on `enrolled`, never on the payment status alone.
 */
final readonly class ClassPaymentVerification
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    /** A free class: nothing to pay. */
    public const NOT_REQUIRED = 'not_required';

    public function __construct(
        public ClassEnrollment $enrollment,
        public string $paymentStatus,
        public ?string $channel = null,
    ) {}

    public function enrolled(): bool
    {
        return $this->enrollment->status === ClassEnrollment::STATUS_CONFIRMED;
    }

    public function toArray(): array
    {
        return [
            'payment_status' => $this->paymentStatus,
            'enrolled' => $this->enrolled(),
            'reference' => $this->enrollment->provider_reference ?? $this->enrollment->payment_id,
            'channel' => $this->channel,
            'paid_at' => $this->enrollment->paid_at?->toIso8601ZuluString(),
        ];
    }
}
