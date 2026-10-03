<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Core\Http\Resources\PlanHistoryEntryResource;
use App\Domains\Core\Http\Resources\PlanNextSessionResource;
use App\Domains\Core\Http\Resources\PlanResource;
use App\Domains\Core\Models\TeacherAccountLink;
use App\Domains\Core\Repositories\PlanRepositoryInterface;
use App\Domains\Core\Repositories\SessionRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The teacher-side aggregate PlanController::nextSessions/history has no
 * equivalent of — a teacher currently has no way to list their own classes
 * across every plan they teach (docs/needed-endpoints-classes.md §3). Reads
 * core.sessions directly (not per-plan) since Session.teacher_id is already
 * a direct column — no need to enumerate the teacher's plans first.
 *
 * Takes the raw public_id (not {teacher:public_id} binding) and resolves it
 * through Core's own teacher_account_links read model, same cross-domain
 * rule PlanController::index follows for learners: Core never reaches into
 * Auth's Teacher model directly.
 */
class TeacherClassController
{
    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
        private readonly PlanRepositoryInterface $plans,
    ) {}

    public function nextSessions(Request $request, string $teacherPublicId): AnonymousResourceCollection
    {
        $teacherId = $this->resolveOwnTeacherId($request, $teacherPublicId);

        return PlanNextSessionResource::collection($this->sessions->nextSessionsForTeacher($teacherId));
    }

    public function history(Request $request, string $teacherPublicId): AnonymousResourceCollection
    {
        $teacherId = $this->resolveOwnTeacherId($request, $teacherPublicId);

        return PlanHistoryEntryResource::collection($this->sessions->historyForTeacher($teacherId));
    }

    /**
     * Lets a caller label a next-sessions/history row by its plan_id
     * (format, days, time_of_day, reference) — there's no subject or
     * learner name available here (CLAUDE.md's cross-domain rule; see
     * PlanRepositoryInterface::forTeacher's docblock).
     */
    public function plans(Request $request, string $teacherPublicId): AnonymousResourceCollection
    {
        $teacherId = $this->resolveOwnTeacherId($request, $teacherPublicId);

        return PlanResource::collection($this->plans->forTeacher($teacherId));
    }

    /** Scoped to the authenticated teacher's own id only — never another teacher's, even another authenticated teacher's. */
    private function resolveOwnTeacherId(Request $request, string $teacherPublicId): int
    {
        $link = TeacherAccountLink::where('public_id', $teacherPublicId)->firstOrFail();

        $link->account_id === $request->user()->id || abort(403);

        return $link->teacher_id;
    }
}
