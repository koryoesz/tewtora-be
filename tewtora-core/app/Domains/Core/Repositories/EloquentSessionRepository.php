<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\Session;
use Illuminate\Database\Eloquent\Collection;

class EloquentSessionRepository implements SessionRepositoryInterface
{
    public function find(int $id): ?Session
    {
        return Session::find($id);
    }

    public function findByPublicId(string $publicId): ?Session
    {
        return Session::where('public_id', $publicId)->first();
    }

    public function upcomingFor(int $learnerProfileId): Collection
    {
        return Session::where('learner_profile_id', $learnerProfileId)
            ->where('status', 'scheduled')
            ->orderBy('scheduled_at')
            ->get();
    }

    public function nextSessionsForTeacher(int $teacherId): Collection
    {
        return Session::where('teacher_id', $teacherId)
            ->where('status', 'scheduled')
            ->with('plan')
            ->orderBy('scheduled_at')
            ->get();
    }

    public function historyForTeacher(int $teacherId): Collection
    {
        // No status filter — mirrors PlanController::history's own per-plan
        // query exactly, for the same resource shape either way.
        return Session::where('teacher_id', $teacherId)
            ->with(['feedback', 'plan'])
            ->orderByDesc('scheduled_at')
            ->get();
    }

    public function create(array $data): Session
    {
        return Session::create($data);
    }

    public function transitionStatus(Session $session, string $status): Session
    {
        // Idempotency for at-least-once event delivery (backend-engineering-
        // standards.md §8): a redelivered event that would repeat the same
        // transition is a no-op, not an error or a duplicate side effect.
        if ($session->status === $status) {
            return $session;
        }

        $session->update(['status' => $status]);

        return $session;
    }
}
