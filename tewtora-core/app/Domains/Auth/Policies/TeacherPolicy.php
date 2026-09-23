<?php

namespace App\Domains\Auth\Policies;

use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\Teacher;

class TeacherPolicy
{
    /** docs/api-contract.md §3: any authenticated account. */
    public function view(Account $account, Teacher $teacher): bool
    {
        return true;
    }

    public function manage(Account $account, Teacher $teacher): bool
    {
        return $teacher->account_id === $account->id;
    }
}
