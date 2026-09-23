<?php

namespace App\Domains\Auth\Policies;

use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\Assessment;
use App\Domains\Auth\Models\LearnerProfile;

class AssessmentPolicy
{
    public function manage(Account $account, Assessment $assessment): bool
    {
        return LearnerProfile::withoutGlobalScopes()
            ->where('id', $assessment->learner_profile_id)
            ->where('owner_account_id', $account->id)
            ->exists();
    }
}
