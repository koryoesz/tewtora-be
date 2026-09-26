<?php

namespace App\Domains\Core\Policies;

use App\Domains\Auth\Models\Account;
use App\Domains\Core\Models\LearnerAccountLink;
use App\Domains\Core\Models\MessageThread;
use App\Domains\Core\Models\TeacherAccountLink;

/**
 * No separate "manage" vs "view" split like PlanPolicy — unlike a plan's
 * booking/payment actions, a linked child login is a full participant in
 * messaging (can read, send, and report), just not able to touch anything
 * booking/payment-shaped, and nothing in this domain's messaging endpoints
 * does that anyway.
 */
class MessageThreadPolicy
{
    public function participate(Account $account, MessageThread $thread): bool
    {
        if ($thread->is_support) {
            return $account->account_type === 'admin'
                || LearnerAccountLink::where('learner_profile_id', $thread->learner_profile_id)
                    ->where(function ($query) use ($account) {
                        $query->where('owner_account_id', $account->id)
                            ->orWhere('linked_login_account_id', $account->id);
                    })
                    ->exists();
        }

        if (LearnerAccountLink::where('learner_profile_id', $thread->learner_profile_id)
            ->where(function ($query) use ($account) {
                $query->where('owner_account_id', $account->id)
                    ->orWhere('linked_login_account_id', $account->id);
            })
            ->exists()) {
            return true;
        }

        return TeacherAccountLink::where('teacher_id', $thread->teacher_id)
            ->where('account_id', $account->id)
            ->exists();
    }
}
