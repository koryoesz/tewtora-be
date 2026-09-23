<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\Feedback;

class EloquentFeedbackRepository implements FeedbackRepositoryInterface
{
    public function find(int $id): ?Feedback
    {
        return Feedback::find($id);
    }

    public function forSession(int $sessionId): ?Feedback
    {
        return Feedback::where('session_id', $sessionId)->first();
    }

    public function firstOrNewDraftForSession(int $sessionId, int $teacherId, int $learnerProfileId): Feedback
    {
        return Feedback::firstOrNew(
            ['session_id' => $sessionId],
            ['teacher_id' => $teacherId, 'learner_profile_id' => $learnerProfileId, 'status' => 'draft']
        );
    }

    public function saveDraft(Feedback $feedback, array $data): Feedback
    {
        $feedback->fill(array_merge($data, ['status' => 'draft']));
        $feedback->save();

        return $feedback;
    }

    public function submit(Feedback $feedback, array $data): Feedback
    {
        $feedback->fill(array_merge($data, ['status' => 'submitted']));
        $feedback->save();

        return $feedback;
    }
}
