<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\TrialRequest;
use Illuminate\Database\Eloquent\Collection;

class EloquentTrialRequestRepository implements TrialRequestRepositoryInterface
{
    public function find(int $id): ?TrialRequest
    {
        return TrialRequest::find($id);
    }

    public function findByPublicId(string $publicId): ?TrialRequest
    {
        return TrialRequest::where('public_id', $publicId)->first();
    }

    public function pendingForTeacher(int $teacherId): Collection
    {
        return TrialRequest::where('teacher_id', $teacherId)
            ->where('status', 'pending')
            ->orderBy('slot_starts_at')
            ->get();
    }

    public function pendingForLearner(int $learnerProfileId): Collection
    {
        return TrialRequest::where('learner_profile_id', $learnerProfileId)
            ->where('status', 'pending')
            ->orderBy('slot_starts_at')
            ->get();
    }

    public function create(array $data): TrialRequest
    {
        return TrialRequest::create($data);
    }

    public function markAccepted(TrialRequest $trialRequest, int $sessionId): TrialRequest
    {
        $trialRequest->update(['status' => 'accepted', 'session_id' => $sessionId]);

        return $trialRequest;
    }

    public function markDeclined(TrialRequest $trialRequest): TrialRequest
    {
        $trialRequest->update(['status' => 'declined']);

        return $trialRequest;
    }

    public function markCancelled(TrialRequest $trialRequest): TrialRequest
    {
        $trialRequest->update(['status' => 'cancelled']);

        return $trialRequest;
    }
}
