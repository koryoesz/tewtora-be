<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Exceptions\AssessmentIncompleteException;
use App\Domains\Auth\Models\Assessment;

class EloquentAssessmentRepository implements AssessmentRepositoryInterface
{
    public function latestForLearner(int $learnerProfileId): ?Assessment
    {
        return Assessment::where('learner_profile_id', $learnerProfileId)
            ->orderByDesc('submitted_at')
            ->first();
    }

    public function firstOrNewDraftForLearner(int $learnerProfileId): Assessment
    {
        return Assessment::firstOrNew(
            ['learner_profile_id' => $learnerProfileId, 'status' => 'draft'],
        );
    }

    public function saveDraft(Assessment $assessment, array $data): Assessment
    {
        $assessment->fill(array_merge($data, ['status' => 'draft']));
        $assessment->save();

        return $assessment;
    }

    public function submit(Assessment $assessment, array $data): Assessment
    {
        $assessment->fill(array_merge($data, ['status' => 'submitted', 'submitted_at' => now()]));

        // Mirrors chk_learning_goals_present_if_submitted — checked here so
        // an assessment submitted with no learning goals (whether from
        // this request or never saved as a draft) gets a clean 422 instead
        // of the DB CHECK rejecting the insert as an uncaught 500.
        if (empty($assessment->learning_goals)) {
            throw new AssessmentIncompleteException;
        }

        $assessment->save();

        return $assessment;
    }
}
