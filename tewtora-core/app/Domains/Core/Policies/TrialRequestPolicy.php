<?php

namespace App\Domains\Core\Policies;

use App\Domains\Auth\Models\Account;
use App\Domains\Core\Models\LearnerAccountLink;
use App\Domains\Core\Models\TeacherAccountLink;
use App\Domains\Core\Models\TrialRequest;

class TrialRequestPolicy
{
    /** Owner only — never the linked-login (view-only) child (mirrors LearnerProfilePolicy's manage()). */
    public function manage(Account $account, TrialRequest $trialRequest): bool
    {
        return LearnerAccountLink::where('learner_profile_id', $trialRequest->learner_profile_id)
            ->where('owner_account_id', $account->id)
            ->exists();
    }

    public function view(Account $account, TrialRequest $trialRequest): bool
    {
        if ($this->manage($account, $trialRequest)) {
            return true;
        }

        if (LearnerAccountLink::where('learner_profile_id', $trialRequest->learner_profile_id)
            ->where('linked_login_account_id', $account->id)
            ->exists()) {
            return true;
        }

        return $this->respond($account, $trialRequest);
    }

    public function respond(Account $account, TrialRequest $trialRequest): bool
    {
        return TeacherAccountLink::where('teacher_id', $trialRequest->teacher_id)
            ->where('account_id', $account->id)
            ->exists();
    }
}
