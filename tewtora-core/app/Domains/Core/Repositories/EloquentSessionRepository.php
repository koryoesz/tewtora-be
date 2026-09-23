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
