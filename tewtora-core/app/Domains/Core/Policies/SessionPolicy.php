<?php

namespace App\Domains\Core\Policies;

use App\Domains\Auth\Models\Account;
use App\Domains\Core\Models\LearnerAccountLink;
use App\Domains\Core\Models\Session;
use App\Domains\Core\Models\TeacherAccountLink;

class SessionPolicy
{
    /** The teacher who taught (or will teach) it — e.g. filing feedback. */
    public function manage(Account $account, Session $session): bool
    {
        return TeacherAccountLink::where('teacher_id', $session->teacher_id)
            ->where('account_id', $account->id)
            ->exists();
    }

    public function view(Account $account, Session $session): bool
    {
        if ($this->manage($account, $session)) {
            return true;
        }

        return LearnerAccountLink::where('learner_profile_id', $session->learner_profile_id)
            ->where(function ($query) use ($account) {
                $query->where('owner_account_id', $account->id)
                    ->orWhere('linked_login_account_id', $account->id);
            })
            ->exists();
    }
}
