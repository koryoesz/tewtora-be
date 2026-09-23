<?php

namespace App\Domains\Core\Policies;

use App\Domains\Auth\Models\Account;
use App\Domains\Core\Models\Feedback;
use App\Domains\Core\Models\LearnerAccountLink;
use App\Domains\Core\Models\TeacherAccountLink;

class FeedbackPolicy
{
    public function manage(Account $account, Feedback $feedback): bool
    {
        return TeacherAccountLink::where('teacher_id', $feedback->teacher_id)
            ->where('account_id', $account->id)
            ->exists();
    }

    public function view(Account $account, Feedback $feedback): bool
    {
        if ($this->manage($account, $feedback)) {
            return true;
        }

        return LearnerAccountLink::where('learner_profile_id', $feedback->learner_profile_id)
            ->where(function ($query) use ($account) {
                $query->where('owner_account_id', $account->id)
                    ->orWhere('linked_login_account_id', $account->id);
            })
            ->exists();
    }
}
