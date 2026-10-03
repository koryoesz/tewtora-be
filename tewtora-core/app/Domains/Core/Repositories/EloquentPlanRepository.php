<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\Plan;
use Illuminate\Database\Eloquent\Collection;

class EloquentPlanRepository implements PlanRepositoryInterface
{
    public function find(int $id): ?Plan
    {
        return Plan::find($id);
    }

    public function findByPublicId(string $publicId): ?Plan
    {
        return Plan::where('public_id', $publicId)->first();
    }

    public function forLearner(int $learnerProfileId): Collection
    {
        return Plan::where('learner_profile_id', $learnerProfileId)->get();
    }

    public function forTeacher(int $teacherId): Collection
    {
        return Plan::where('teacher_id', $teacherId)->get();
    }

    public function create(array $data): Plan
    {
        return Plan::create($data);
    }

    public function pause(Plan $plan): Plan
    {
        $plan->update(['status' => 'paused', 'renews_at' => null]);

        return $plan;
    }

    public function resume(Plan $plan, int $sessionsRemaining): Plan
    {
        $plan->update([
            'status' => 'active',
            'sessions_remaining' => $sessionsRemaining,
            'renews_at' => now()->addMonth(),
        ]);

        return $plan;
    }

    public function end(Plan $plan): Plan
    {
        $plan->update(['status' => 'ended', 'renews_at' => null]);

        return $plan;
    }
}
