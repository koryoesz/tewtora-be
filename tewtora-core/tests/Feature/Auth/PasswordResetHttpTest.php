<?php

namespace Tests\Feature\Auth;

use App\Domains\Auth\Mail\PasswordChanged;
use App\Domains\Auth\Mail\PasswordResetLink;
use App\Domains\Auth\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/** POST /auth/forgot-password and /auth/reset-password — see PasswordResetService. */
class PasswordResetHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    private const GENERIC_MESSAGE = "If an account exists for that email, we've sent a link to reset the password.";

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function makeParent(): Account
    {
        return $this->makeAccount(['email' => 'ada@example.test', 'password_hash' => password_hash('old-password', PASSWORD_ARGON2ID)]);
    }

    /** Requests a reset link and returns the plain token from the queued email, as the user would receive it. */
    private function requestResetToken(string $email = 'ada@example.test'): string
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(202);

        $token = null;
        Mail::assertQueued(PasswordResetLink::class, function (PasswordResetLink $mail) use (&$token) {
            $token = $mail->token;

            return true;
        });

        return $token;
    }

    private function reset(array $overrides = [])
    {
        return $this->postJson('/api/v1/auth/reset-password', array_merge([
            'email' => 'ada@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ], $overrides));
    }

    public function test_a_reset_link_is_emailed_for_an_existing_account(): void
    {
        $this->makeParent();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.test'])
            ->assertStatus(202)
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        Mail::assertQueued(PasswordResetLink::class, fn (PasswordResetLink $mail) => $mail->hasTo('ada@example.test'));
    }

    public function test_only_a_hash_of_the_token_is_stored(): void
    {
        $this->makeParent();

        $token = $this->requestResetToken();
        $stored = DB::table('auth.password_reset_tokens')->where('email', 'ada@example.test')->value('token');

        $this->assertNotSame($token, $stored);
        $this->assertTrue(password_verify($token, $stored));
    }

    /** Same 202 and body either way, so the endpoint can't reveal which emails have accounts. */
    public function test_an_unknown_email_gets_the_same_response_and_no_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])
            ->assertStatus(202)
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        Mail::assertNothingQueued();
    }

    public function test_a_soft_deleted_account_gets_no_reset_link(): void
    {
        $this->makeParent()->delete();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.test'])->assertStatus(202);

        Mail::assertNothingQueued();
    }

    public function test_a_repeat_request_within_a_minute_sends_no_second_link(): void
    {
        $this->makeParent();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.test'])->assertStatus(202);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.test'])
            ->assertStatus(202)
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        Mail::assertQueuedCount(1);
    }

    public function test_forgot_password_requires_a_valid_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['email']]]);
    }

    public function test_a_valid_token_resets_the_password(): void
    {
        $this->makeParent();
        $token = $this->requestResetToken();

        $this->reset(['token' => $token])->assertNoContent();

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'brand-new-password'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'old-password'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_a_reset_signs_the_account_out_everywhere(): void
    {
        $account = $this->makeParent();
        $this->tokenFor($account);
        $this->tokenFor($account);
        $token = $this->requestResetToken();

        $this->reset(['token' => $token])->assertNoContent();

        $this->assertSame(0, $account->tokens()->count());
    }

    public function test_a_reset_sends_a_password_changed_notice(): void
    {
        $this->makeParent();
        $token = $this->requestResetToken();

        $this->reset(['token' => $token])->assertNoContent();

        Mail::assertQueued(PasswordChanged::class, fn (PasswordChanged $mail) => $mail->hasTo('ada@example.test'));
    }

    /** All-or-nothing: a failure while signing the account out must not leave a half-done reset. */
    public function test_a_failure_mid_reset_changes_nothing(): void
    {
        $this->makeParent();
        $token = $this->requestResetToken();

        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'delete') && str_contains($query->sql, 'personal_access_tokens')) {
                throw new RuntimeException('token revocation failed');
            }
        });

        $this->reset(['token' => $token])->assertStatus(500);

        $this->assertTrue(password_verify('old-password', Account::where('email', 'ada@example.test')->value('password_hash')));
        $this->assertTrue(DB::table('auth.password_reset_tokens')->where('email', 'ada@example.test')->exists());
        Mail::assertNotQueued(PasswordChanged::class);
    }

    public function test_a_token_can_only_be_used_once(): void
    {
        $this->makeParent();
        $token = $this->requestResetToken();

        $this->reset(['token' => $token])->assertNoContent();

        $this->reset(['token' => $token, 'password' => 'another-password', 'password_confirmation' => 'another-password'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_reset_token');
    }

    public function test_a_wrong_token_is_rejected_and_the_password_is_unchanged(): void
    {
        $this->makeParent();
        $this->requestResetToken();

        $this->reset(['token' => 'not-the-real-token'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_reset_token');

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'old-password'])->assertOk();
        Mail::assertNotQueued(PasswordChanged::class);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $this->makeParent();
        $token = $this->requestResetToken();

        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        $this->reset(['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_reset_token');
    }

    /** Same code as a bad token — a reset can't be used to probe which emails exist either. */
    public function test_a_reset_for_an_unknown_email_looks_like_a_bad_token(): void
    {
        $this->reset(['email' => 'nobody@example.test', 'token' => 'anything'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_reset_token');
    }

    public function test_the_new_password_must_meet_the_sign_up_rules(): void
    {
        $this->makeParent();
        $token = $this->requestResetToken();

        $this->reset(['token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['password']]]);

        $this->reset(['token' => $token, 'password_confirmation' => 'something-else'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['password']]]);
    }
}
