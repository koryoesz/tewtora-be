<?php

namespace App\Domains\Core\Services;

use App\Domains\Core\Exceptions\FeedbackAlreadySubmittedException;
use App\Domains\Core\Models\Feedback;
use App\Domains\Core\Models\OutboxEvent;
use App\Domains\Core\Repositories\FeedbackRepositoryInterface;
use Illuminate\Support\Facades\DB;

class FeedbackService
{
    public function __construct(
        private readonly FeedbackRepositoryInterface $feedback,
    ) {}

    public function saveDraft(Feedback $feedback, array $data): Feedback
    {
        $this->assertNotAlreadySubmitted($feedback);

        return $this->feedback->saveDraft($feedback, $data);
    }

    /**
     * docs/api-contract.md §11: filing feedback is what releases the
     * session's held payment. The event is written to core.outbox_events
     * in the same transaction as the feedback write (CLAUDE.md's outbox
     * rule) — never published to the queue directly from here — so a crash
     * between the two can't produce a submitted-but-never-released session.
     * See microservices-architecture.md §5 for why this is a distinct
     * SessionFeedbackFiled event rather than reusing SessionCompleted.
     */
    public function submit(Feedback $feedback, array $data): Feedback
    {
        $this->assertNotAlreadySubmitted($feedback);

        return DB::transaction(function () use ($feedback, $data) {
            $feedback = $this->feedback->submit($feedback, $data);

            OutboxEvent::create([
                'aggregate_type' => 'feedback',
                'aggregate_id' => $feedback->id,
                'event_type' => 'SessionFeedbackFiled',
                'payload' => [
                    'session_id' => $feedback->session_id,
                    'feedback_id' => $feedback->id,
                ],
            ]);

            return $feedback;
        });
    }

    private function assertNotAlreadySubmitted(Feedback $feedback): void
    {
        if ($feedback->status === 'submitted') {
            throw new FeedbackAlreadySubmittedException($feedback->id);
        }
    }
}
