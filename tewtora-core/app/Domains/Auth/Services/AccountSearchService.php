<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Models\Account;
use App\Shared\Logging\Auditable;
use Laravel\Sanctum\NewAccessToken;

/**
 * docs/api-contract.md §18. `search()` itself is audited, not just
 * opening a result — "searching is logged, not just opening a result."
 */
class AccountSearchService
{
    use Auditable;

    private const ALLOWED_ACT_AS_REASONS = [
        'Parent asked support for help',
        'Investigating a payment issue',
        'Verifying a safeguarding report',
        'Resolving a stuck-money case',
    ];

    public function search(string $query)
    {
        $this->audit('viewed', 'account', 'search:'.$query);

        return Account::withoutGlobalScopes()
            ->where('email', 'like', "%{$query}%")
            ->limit(25)
            ->get();
    }

    public function view(Account $account): Account
    {
        $this->audit('viewed', 'account', (string) $account->public_id);

        return $account;
    }

    /** docs/api-contract.md §18: reason "required, from a constrained list, not free text." */
    public function actAs(Account $account, string $reason): NewAccessToken
    {
        if (! in_array($reason, self::ALLOWED_ACT_AS_REASONS, true)) {
            abort(422, 'reason must be one of the allowed values.');
        }

        $this->audit('acted_as', 'account', (string) $account->public_id, $reason);

        return $account->createToken('support-act-as', ['act-as-readonly']);
    }

    public static function allowedActAsReasons(): array
    {
        return self::ALLOWED_ACT_AS_REASONS;
    }
}
