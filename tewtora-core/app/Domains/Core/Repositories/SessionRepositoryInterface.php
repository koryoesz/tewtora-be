<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\Session;
use Illuminate\Database\Eloquent\Collection;

/** backend-engineering-standards.md §2's own worked example. */
interface SessionRepositoryInterface
{
    public function find(int $id): ?Session;

    public function findByPublicId(string $publicId): ?Session;

    public function upcomingFor(int $learnerProfileId): Collection;

    /** Every scheduled session across every plan this teacher teaches — the teacher-side aggregate PlanController::nextSessions mirrors per-plan. */
    public function nextSessionsForTeacher(int $teacherId): Collection;

    /** Every past/non-scheduled session across every plan this teacher teaches, feedback eager-loaded — mirrors PlanController::history. */
    public function historyForTeacher(int $teacherId): Collection;

    public function create(array $data): Session;

    public function transitionStatus(Session $session, string $status): Session;
}
