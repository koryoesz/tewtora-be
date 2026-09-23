<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Assessment;

interface AssessmentRepositoryInterface
{
    public function latestForLearner(int $learnerProfileId): ?Assessment;

    public function firstOrNewDraftForLearner(int $learnerProfileId): Assessment;

    public function saveDraft(Assessment $assessment, array $data): Assessment;

    public function submit(Assessment $assessment, array $data): Assessment;
}
