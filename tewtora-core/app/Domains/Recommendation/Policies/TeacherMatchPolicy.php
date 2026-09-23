<?php

namespace App\Domains\Recommendation\Policies;

use App\Domains\Auth\Models\Account;
use App\Domains\Recommendation\Models\TeacherMatch;
use App\Domains\Recommendation\Models\TeacherProfileView;

/**
 * backend-engineering-standards.md §4: authorization lives here, not
 * inline in a controller. Resolves "is this account the match's teacher"
 * via Recommendation's own teacher_profile_view.account_id — never by
 * importing Auth's Teacher model (CLAUDE.md's hard rule).
 */
class TeacherMatchPolicy
{
    public function respond(Account $account, TeacherMatch $match): bool
    {
        return TeacherProfileView::where('id', $match->teacher_id)
            ->where('account_id', $account->id)
            ->exists();
    }

    public function view(Account $account, TeacherMatch $match): bool
    {
        return $this->respond($account, $match);
    }
}
