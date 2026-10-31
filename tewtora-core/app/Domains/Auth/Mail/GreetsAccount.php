<?php

namespace App\Domains\Auth\Mail;

use App\Domains\Auth\Models\Account;

/**
 * "Hi Amaka," when there's a name to use, "Hi there," otherwise. Only a
 * teacher has a name on file (auth.teachers.full_name); accounts themselves
 * have no name column for any role.
 */
trait GreetsAccount
{
    protected function firstNameOf(Account $account, ?string $name = null): ?string
    {
        $name ??= $account->account_type === 'teacher' ? $account->teacher?->full_name : null;
        $first = $name !== null ? strtok(trim($name), ' ') : false;

        return $first === false ? null : $first;
    }

    protected function greetingFor(Account $account, ?string $name = null): string
    {
        $first = $this->firstNameOf($account, $name);

        return $first !== null ? "Hi {$first}," : 'Hi there,';
    }
}
