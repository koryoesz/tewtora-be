<?php

namespace App\Domains\Recommendation\Repositories;

use App\Domains\Recommendation\Models\TeacherMatch;
use Illuminate\Database\Eloquent\Collection;

class EloquentTeacherMatchRepository implements TeacherMatchRepositoryInterface
{
    public function find(int $id): ?TeacherMatch
    {
        return TeacherMatch::find($id);
    }

    public function pendingForTeacher(int $teacherId): Collection
    {
        return TeacherMatch::where('teacher_id', $teacherId)
            ->where('status', 'proposed')
            ->orderByDesc('created_at')
            ->get();
    }

    public function forLearner(int $learnerProfileId): Collection
    {
        return TeacherMatch::where('learner_profile_id', $learnerProfileId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function accept(TeacherMatch $match): TeacherMatch
    {
        $match->update(['status' => 'accepted']);

        return $match;
    }

    public function decline(TeacherMatch $match, string $reason): TeacherMatch
    {
        $match->update(['status' => 'declined', 'decline_reason' => $reason]);

        return $match;
    }
}
