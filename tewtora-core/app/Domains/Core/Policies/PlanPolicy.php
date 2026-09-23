<?php

namespace App\Domains\Core\Policies;

use App\Domains\Auth\Models\Account;
use App\Domains\Core\Models\LearnerAccountLink;
use App\Domains\Core\Models\Plan;
use App\Domains\Core\Models\TeacherAccountLink;

class PlanPolicy
{
    /** Only the owner can pause/resume/end — never the linked-only login (mirrors LearnerProfilePolicy). */
    public function manage(Account $account, Plan $plan): bool
    {
        return LearnerAccountLink::where('learner_profile_id', $plan->learner_profile_id)
            ->where('owner_account_id', $account->id)
            ->exists();
    }

    public function view(Account $account, Plan $plan): bool
    {
        if ($this->manage($account, $plan)) {
            return true;
        }

        if (LearnerAccountLink::where('learner_profile_id', $plan->learner_profile_id)
            ->where('linked_login_account_id', $account->id)
            ->exists()) {
            return true;
        }

        return TeacherAccountLink::where('teacher_id', $plan->teacher_id)
            ->where('account_id', $account->id)
            ->exists();
    }
}
