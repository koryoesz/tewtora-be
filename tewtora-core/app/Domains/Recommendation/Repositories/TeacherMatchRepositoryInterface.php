<?php

namespace App\Domains\Recommendation\Repositories;

use App\Domains\Recommendation\Models\TeacherMatch;
use Illuminate\Database\Eloquent\Collection;

interface TeacherMatchRepositoryInterface
{
    // `matches` has no public_id column (see database-design.md §3.4) — id
    // only, deliberately: it's always reached via a nested route
    // (/teachers/:id/match-requests, /move-requests?...), never looked up
    // by itself from a public URL.
    public function find(int $id): ?TeacherMatch;

    /** Teacher's pending-request inbox. */
    public function pendingForTeacher(int $teacherId): Collection;

    public function forLearner(int $learnerProfileId): Collection;

    public function accept(TeacherMatch $match): TeacherMatch;

    public function decline(TeacherMatch $match, string $reason): TeacherMatch;
}
