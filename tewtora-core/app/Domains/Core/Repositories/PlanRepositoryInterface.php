<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\Plan;
use Illuminate\Database\Eloquent\Collection;

interface PlanRepositoryInterface
{
    public function find(int $id): ?Plan;

    public function findByPublicId(string $publicId): ?Plan;

    public function forLearner(int $learnerProfileId): Collection;

    public function create(array $data): Plan;

    public function pause(Plan $plan): Plan;

    public function resume(Plan $plan, int $sessionsRemaining): Plan;

    public function end(Plan $plan): Plan;
}
