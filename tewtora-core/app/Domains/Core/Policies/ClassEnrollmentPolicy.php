<?php

namespace App\Domains\Core\Policies;

use App\Domains\Auth\Contracts\AuthDirectory;
use App\Domains\Auth\Models\Account;
use App\Domains\Core\Models\ClassEnrollment;

class ClassEnrollmentPolicy
{
    public function __construct(
        private readonly AuthDirectory $auth,
    ) {}

    /** The payer, the learner (or their owner, or their own child login), and admins. */
    public function view(Account $account, ClassEnrollment $enrollment): bool
    {
        return $account->account_type === 'admin'
            || $enrollment->payer_account_id === $account->id
            || in_array($enrollment->learner_profile_id, $this->auth->learnerIdsVisibleTo($account->id), true);
    }

    /** Only whoever is paying can poke at the payment. */
    public function pay(Account $account, ClassEnrollment $enrollment): bool
    {
        return $enrollment->payer_account_id === $account->id;
    }
}
