<?php

namespace App\Domains\Core\Jobs;

use App\Domains\Core\Services\ClassEnrollmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Consumes PaymentFailed (microservices-architecture.md §5) for class
 * enrolments: marks the payment failed on the staff payments view.
 * Idempotent, and never overrides a payment already recorded as paid.
 * Payments for any other purpose are ignored.
 */
class RecordClassEnrollmentPaymentFailure implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @param  array{payment_id: string, purpose: string, purpose_ref: string}  $payload */
    public function __construct(
        public readonly array $payload,
    ) {}

    public function handle(ClassEnrollmentService $service): void
    {
        if (($this->payload['purpose'] ?? null) !== ClassEnrollmentService::PAYMENT_PURPOSE) {
            return;
        }

        $service->recordPaymentFailure($this->payload['purpose_ref'], $this->payload['payment_id']);
    }
}
