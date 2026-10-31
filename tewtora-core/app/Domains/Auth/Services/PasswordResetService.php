<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Exceptions\InvalidPasswordResetTokenException;
use App\Domains\Auth\Mail\PasswordChanged;
use App\Domains\Auth\Mail\PasswordResetLink;
use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Repositories\AccountRepositoryInterface;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Forgot/reset password for email+password accounts. A child's login has
 * no email and is never reset here; the parent sets a new PIN instead
 * (LearnerProfileController::setPin).
 *
 * Built on Laravel's password broker (config/auth.php `passwords.users`),
 * which stores only a hash of the token, expires it after `expire`
 * minutes, allows one link per email per `throttle` seconds, and timeboxes
 * both calls so response time doesn't reveal whether an email has an
 * account.
 */
class PasswordResetService
{
    public function __construct(
        private readonly AccountRepositoryInterface $accounts,
    ) {}

    /**
     * Always "succeeds" from the caller's point of view: an unknown email
     * and a throttled repeat request look exactly like a sent link, so the
     * endpoint can't be used to discover which emails have accounts.
     */
    public function sendResetLink(string $email): void
    {
        $status = Password::broker()->sendResetLink(['email' => $email], function (Account $account, string $token) {
            $this->queueMail($account, new PasswordResetLink($account, $token));
        });

        // The link may already be queued; a logging failure mustn't turn that into an error.
        rescue(fn () => Log::channel('auth')->info('password reset requested', ['status' => $status]), report: false);
    }

    /**
     * @param  array{email: string, token: string, password: string, password_confirmation: string}  $credentials
     *
     * @throws InvalidPasswordResetTokenException
     */
    public function reset(array $credentials): void
    {
        $resetAccount = null;

        // One transaction: the new password, revoking every login token and
        // consuming the reset token (the broker deletes it after the callback)
        // all land together, or none of them do. No half-reset account whose
        // old sessions survive, and no spent token for a password that didn't change.
        // A regular closure, not fn(): an arrow function captures $resetAccount
        // by value, so the inner callback's assignment would never reach here.
        $status = DB::transaction(function () use ($credentials, &$resetAccount) {
            return Password::broker()->reset($credentials, function (Account $account, string $password) use (&$resetAccount) {
                $this->accounts->updatePasswordHash($account, Hash::make($password));

                // Signs the account out everywhere: whoever reset the password
                // may be locking out someone who had got in with the old one.
                $account->tokens()->delete();

                $resetAccount = $account;
            });
        });

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw new InvalidPasswordResetTokenException;
        }

        $this->bestEffort($resetAccount, function () use ($resetAccount) {
            Log::channel('auth')->info('password reset', ['account_id' => $resetAccount->public_id]);
            Mail::to($resetAccount->email)->queue(new PasswordChanged($resetAccount));
        });
    }

    private function queueMail(Account $account, Mailable $mail): void
    {
        $this->bestEffort($account, fn () => Mail::to($account->email)->queue($mail));
    }

    /**
     * For side effects after the real change is committed (logging, queueing
     * an email): they must never turn a completed request into an error, same
     * as AuthSessionService::afterRegistration().
     */
    private function bestEffort(Account $account, callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            rescue(fn () => Log::channel('auth')->error('password follow-up step failed', [
                'account_id' => $account->public_id,
                'exception' => $e,
            ]), report: false);
        }
    }
}
