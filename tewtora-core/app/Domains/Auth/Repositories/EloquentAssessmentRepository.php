<?php

namespace App\Domains\Auth\Repositories;

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
        $assessment->save();

        return $assessment;
    }
}
