<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\Plan;
use Illuminate\Database\Eloquent\Collection;

interface PlanRepositoryInterface
{
    public function find(int $id): ?Plan;

    public function findByPublicId(string $publicId): ?Plan;

    public function forLearner(int $learnerProfileId): Collection;

    /**
     * Every plan this teacher teaches, any status — lets the teacher-side
     * classes list (TeacherClassController) label a session/history row
     * by plan_id (format, days, time_of_day, reference) since Core can't
     * cross into Auth for a subject/learner name (CLAUDE.md's hard rule).
     */
    public function forTeacher(int $teacherId): Collection;

    public function create(array $data): Plan;

    public function pause(Plan $plan): Plan;

    public function resume(Plan $plan, int $sessionsRemaining): Plan;

    public function end(Plan $plan): Plan;
}
