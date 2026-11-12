<?php

namespace App\Domains\Core\Jobs;

use App\Domains\Core\Services\ClassEnrollmentService;
use App\Domains\Core\Services\Payments\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Consumes PaymentSucceeded (microservices-architecture.md §5) for class
 * enrolments. Idempotent: ClassEnrollmentService::confirmPayment() is a
 * no-op for a payment it has already recorded, so a redelivered event (or
 * one racing the payer's own "verify payment" call) never double-confirms.
 * Payments for any other purpose are ignored.
 */
class ConfirmClassEnrollmentPayment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @param  array{payment_id: string, purpose: string, purpose_ref: string, amount_minor: int, currency_code: string, provider_reference?: ?string, channel?: ?string, paid_at?: ?string}  $payload */
    public function __construct(
        public readonly array $payload,
    ) {}

    public function handle(ClassEnrollmentService $service): void
    {
        if (($this->payload['purpose'] ?? null) !== ClassEnrollmentService::PAYMENT_PURPOSE) {
            return;
        }

        $service->confirmPayment($this->payload['purpose_ref'], new PaymentStatus(
            $this->payload['payment_id'],
            'success',
            (int) $this->payload['amount_minor'],
            $this->payload['currency_code'],
            $this->payload['provider_reference'] ?? null,
            $this->payload['channel'] ?? null,
            isset($this->payload['paid_at']) ? CarbonImmutable::parse($this->payload['paid_at']) : null,
            $this->payload['purpose'],
            $this->payload['purpose_ref'],
        ));
    }
}
