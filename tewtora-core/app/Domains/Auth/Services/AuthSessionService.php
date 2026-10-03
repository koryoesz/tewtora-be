<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Exceptions\ChildSignInPausedException;
use App\Domains\Auth\Exceptions\InvalidCredentialsException;
use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\LearnerProfile;
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

    /**
     * @param  array{email?: ?string, password?: ?string, username?: ?string, pin?: ?string}  $credentials
     *                                                                                                      Exactly one pair populated — LoginRequest enforces that.
     */
    public function login(array $credentials): NewAccessToken
    {
        if (! empty($credentials['username'])) {
            return $this->loginChild($credentials['username'], $credentials['pin']);
        }

        return $this->loginWithPassword($credentials['email'], $credentials['password']);
    }

    private function loginWithPassword(string $email, string $password): NewAccessToken
    {
        $account = $this->accounts->findByEmail($email);

        if (! $account || ! Hash::check($password, $account->password_hash)) {
            throw new InvalidCredentialsException;
        }

        return $account->createToken('web', self::ABILITIES[$account->account_type] ?? []);
    }

    /**
     * A child has no password of its own — verified against the linked
     * learner profile's pin_hash instead (set via
     * LearnerProfileController::setPin/store). Same InvalidCredentialsException
     * either way, so a wrong username can't be distinguished from a wrong
     * PIN, or from a username that isn't linked to a profile at all.
     */
    private function loginChild(string $username, string $pin): NewAccessToken
    {
        $account = $this->accounts->findByUsername($username);

        if (! $account || $account->account_type !== 'child') {
            throw new InvalidCredentialsException;
        }

        $profile = LearnerProfile::withoutGlobalScopes()
            ->where('linked_login_account_id', $account->id)
            ->first();

        if (! $profile || ! $profile->pin_hash || ! Hash::check($pin, $profile->pin_hash)) {
            throw new InvalidCredentialsException;
        }

        // Checked after the PIN, not before: a wrong PIN against a paused
        // profile should still read as "incorrect sign-in details," not
        // leak that the PIN would otherwise have been right.
        if ($profile->sign_in_paused) {
            throw new ChildSignInPausedException;
        }

        return $account->createToken('web', self::ABILITIES['child'] ?? []);
    }

    public function logout(Account $account): void
    {
        $account->currentAccessToken()?->delete();
    }
}
