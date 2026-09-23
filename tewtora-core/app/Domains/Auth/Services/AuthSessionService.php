<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Exceptions\InvalidCredentialsException;
use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Repositories\AccountRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

/**
 * backend-engineering-standards.md §4: token abilities assigned per role
 * at login, not fixed on the model.
 */
class AuthSessionService
{
    private const ABILITIES = [
        'parent' => ['learner:*', 'session:*', 'payment:pay'],
        'independent_student' => ['learner:*', 'session:*', 'payment:pay'],
        'child' => ['learner:view'],
        'teacher' => ['match:respond', 'session:*', 'feedback:submit', 'payout:read'],
        'admin' => ['admin:*'],
    ];

    public function __construct(
        private readonly AccountRepositoryInterface $accounts,
    ) {}

    public function login(string $email, string $password): NewAccessToken
    {
        $account = $this->accounts->findByEmail($email);

        if (! $account || ! Hash::check($password, $account->password_hash)) {
            throw new InvalidCredentialsException;
        }

        return $account->createToken('web', self::ABILITIES[$account->account_type] ?? []);
    }

    public function logout(Account $account): void
    {
        $account->currentAccessToken()?->delete();
    }
}
