<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\TrialRequest;
use Illuminate\Database\Eloquent\Collection;

interface TrialRequestRepositoryInterface
{
    public function find(int $id): ?TrialRequest;

    public function findByPublicId(string $publicId): ?TrialRequest;

    public function pendingForTeacher(int $teacherId): Collection;

    public function pendingForLearner(int $learnerProfileId): Collection;

    public function create(array $data): TrialRequest;

    public function markAccepted(TrialRequest $trialRequest, int $sessionId): TrialRequest;

    public function markDeclined(TrialRequest $trialRequest): TrialRequest;

    public function markCancelled(TrialRequest $trialRequest): TrialRequest;
}
