<?php

namespace App\Domains\Auth\Policies;

use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\LearnerProfile;

/** backend-engineering-standards.md §4's own worked example, verbatim. */
class LearnerProfilePolicy
{
    public function view(Account $account, LearnerProfile $profile): bool
    {
        return $profile->owner_account_id === $account->id
            || $profile->linked_login_account_id === $account->id; // view-only child login
    }

    public function manage(Account $account, LearnerProfile $profile): bool
    {
        // Only the owner can book, pay, or edit — never the linked-only login.
        return $profile->owner_account_id === $account->id;
    }
}
