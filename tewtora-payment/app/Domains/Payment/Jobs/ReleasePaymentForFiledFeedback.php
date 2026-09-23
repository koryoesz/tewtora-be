<?php

namespace App\Domains\Payment\Jobs;

use App\Domains\Payment\Repositories\PaymentLineItemRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Consumes SessionFeedbackFiled (microservices-architecture.md §5):
 * releases every 'held' payment_line_items row for the session. Naturally
 * idempotent, not just documented as such — releaseHeldForSession() only
 * ever touches rows still 'held', so a redelivered event finds nothing
 * left to do and is a genuine no-op, never a duplicate release
 * (backend-engineering-standards.md §8).
 */
class ReleasePaymentForFiledFeedback implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $sessionId,
        private readonly int $feedbackId,
    ) {}

    public function handle(PaymentLineItemRepositoryInterface $lineItems): void
    {
        $released = $lineItems->releaseHeldForSession($this->sessionId);

        Log::channel('payment')->info('Released payment line items for filed feedback', [
            'session_id' => $this->sessionId,
            'feedback_id' => $this->feedbackId,
            'released_count' => $released->count(),
        ]);
    }
}
