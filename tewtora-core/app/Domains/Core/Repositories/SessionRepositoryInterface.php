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

    /**
     * Every scheduled session across every plan this teacher teaches — the
     * teacher-side aggregate PlanController::nextSessions mirrors per-plan.
     * `plan` is eager-loaded (same-schema, unlike learner_profile_id/
     * teacher_id) so PlanNextSessionResource can expose plan_id — without
     * it, a teacher aggregate response has no way to tell which of a
     * teacher's several plans a row belongs to (flagged live by frontend
     * integration work: "no plan_id, subject, or counterpart name anywhere
     * in the response to label a row with").
     */
    public function nextSessionsForTeacher(int $teacherId): Collection;

    /**
     * Every past/non-scheduled session across every plan this teacher
     * teaches, feedback + plan eager-loaded — mirrors PlanController::history.
     */
    public function historyForTeacher(int $teacherId): Collection;

    public function create(array $data): Session;

    public function transitionStatus(Session $session, string $status): Session;
}
