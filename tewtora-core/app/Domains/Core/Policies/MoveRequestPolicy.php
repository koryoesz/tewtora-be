<?php

namespace App\Domains\Core\Policies;

use App\Domains\Auth\Models\Account;
use App\Domains\Core\Models\LearnerAccountLink;
use App\Domains\Core\Models\MoveRequest;
use App\Domains\Core\Models\TeacherAccountLink;

class MoveRequestPolicy
{
    public function manage(Account $account, MoveRequest $moveRequest): bool
    {
        $plan = $moveRequest->plan;

        return LearnerAccountLink::where('learner_profile_id', $plan->learner_profile_id)
            ->where('owner_account_id', $account->id)
            ->exists();
    }

    /** docs/api-contract.md §5: a child can view a pending move request affecting their own plan, read-only. */
    public function view(Account $account, MoveRequest $moveRequest): bool
    {
        if ($this->manage($account, $moveRequest) || $this->respond($account, $moveRequest)) {
            return true;
        }

        $plan = $moveRequest->plan;

        return LearnerAccountLink::where('learner_profile_id', $plan->learner_profile_id)
            ->where('linked_login_account_id', $account->id)
            ->exists();
    }

    public function respond(Account $account, MoveRequest $moveRequest): bool
    {
        return TeacherAccountLink::where('teacher_id', $moveRequest->plan->teacher_id)
            ->where('account_id', $account->id)
            ->exists();
    }
}
