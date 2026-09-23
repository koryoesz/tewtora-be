<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\Feedback;

interface FeedbackRepositoryInterface
{
    public function find(int $id): ?Feedback;

    public function forSession(int $sessionId): ?Feedback;

    public function firstOrNewDraftForSession(int $sessionId, int $teacherId, int $learnerProfileId): Feedback;

    public function saveDraft(Feedback $feedback, array $data): Feedback;

    public function submit(Feedback $feedback, array $data): Feedback;
}
