<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Exceptions\ChildSignInPausedException;
use App\Domains\Auth\Exceptions\EmailAlreadyRegisteredException;
use App\Domains\Auth\Exceptions\InvalidCredentialsException;
use App\Domains\Auth\Mail\WelcomeToTewtora;
use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\LearnerProfile;
use App\Domains\Auth\Models\OutboxEvent;
use App\Domains\Auth\Repositories\AccountRepositoryInterface;
use App\Domains\Auth\Repositories\TeacherRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\NewAccessToken;
use Throwable;

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
        private readonly TeacherRepositoryInterface $teachers,
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

    /**
     * Creates the account and signs it straight in, so a sign-up returns
     * the same token shape as login(). email_verified_at stays null — no
     * verification-mail flow exists yet.
     *
     * All-or-nothing: the account, a teacher's pending auth.teachers row
     * (TeacherRepositoryInterface::createApplicant), the TeacherRegistered
     * outbox event and the login token are written in one transaction, so a
     * failure at any step leaves no account behind. Core's
     * teacher_account_links and Recommendation's teacher_profile_view are
     * built from that event, never written from here.
     *
     * Logging and queueing the welcome email happen after the commit and are
     * best-effort (afterRegistration()): once the account and its token
     * exist, they can no longer turn the sign-up into an error.
     *
     * @param  array{account_type: string, email: string, password: string, phone?: ?string, name?: string}  $data
     */
    public function register(array $data): NewAccessToken
    {
        try {
            [$account, $token] = DB::transaction(function () use ($data) {
                // public_id is DB-generated (DEFAULT (UUID())) — create()'s
                // in-memory model doesn't know it without a refresh.
                $account = $this->accounts->create([
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password_hash' => Hash::make($data['password']),
                    'account_type' => $data['account_type'],
                ])->refresh();

                if ($account->account_type === 'teacher') {
                    $teacher = $this->teachers->createApplicant($account, $data['name']);

                    OutboxEvent::create([
                        'aggregate_type' => 'teacher',
                        'aggregate_id' => $teacher->id,
                        'event_type' => 'TeacherRegistered',
                        'payload' => [
                            'teacher_id' => $teacher->id,
                            'public_id' => $teacher->public_id,
                            'account_id' => $account->id,
                            'verification_status' => $teacher->verification_status,
                        ],
                    ]);
                }

                // Inside the transaction (personal_access_tokens is on the same
                // connection): a failed token insert must roll the account back,
                // not leave a saved account behind a 500.
                $token = $account->createToken('web', self::ABILITIES[$account->account_type] ?? []);

                return [$account, $token];
            });
        } catch (UniqueConstraintViolationException) {
            throw new EmailAlreadyRegisteredException;
        }

        $this->afterRegistration($account, $data['name'] ?? null);

        return $token;
    }

    /**
     * Never throws: the account is committed and the caller gets its token
     * either way. Only pushes the welcome email onto the `mail` queue
     * (QueuedMailable); the send, its retries and Resend rate limiting
     * happen in the worker.
     */
    private function afterRegistration(Account $account, ?string $name): void
    {
        try {
            Log::channel('auth')->info('account registered', [
                'account_id' => $account->public_id,
                'account_type' => $account->account_type,
            ]);

            Mail::to($account->email)->queue(new WelcomeToTewtora($account, $name));
        } catch (Throwable $e) {
            // rescue(): if logging itself is what failed, don't let reporting
            // that failure throw a second time.
            rescue(fn () => Log::channel('auth')->error('post-registration step failed', [
                'account_id' => $account->public_id,
                'exception' => $e,
            ]), report: false);
        }
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
