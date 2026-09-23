<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\MoveRequest;
use App\Domains\Core\Models\Plan;
use Illuminate\Database\Eloquent\Collection;

class EloquentMoveRequestRepository implements MoveRequestRepositoryInterface
{
    public function find(int $id): ?MoveRequest
    {
        return MoveRequest::find($id);
    }

    public function findByPublicId(string $publicId): ?MoveRequest
    {
        return MoveRequest::where('public_id', $publicId)->first();
    }

    /** docs/api-contract.md §5: master list, mixes kind: 'move' and 'renewal'. */
    public function forTeacher(int $teacherId): Collection
    {
        $planIds = Plan::where('teacher_id', $teacherId)->pluck('id');

        return MoveRequest::whereIn('plan_id', $planIds)->orderByDesc('created_at')->get();
    }

    public function forLearner(int $learnerProfileId): Collection
    {
        $planIds = Plan::where('learner_profile_id', $learnerProfileId)->pluck('id');

        return MoveRequest::whereIn('plan_id', $planIds)->orderByDesc('created_at')->get();
    }

    public function create(array $data): MoveRequest
    {
        return MoveRequest::create($data);
    }
}
