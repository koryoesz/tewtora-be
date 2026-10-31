<?php

namespace Tests\Feature\Auth;

use App\Domains\Auth\Mail\WelcomeToTewtora;
use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\OutboxEvent;
use App\Domains\Auth\Models\Teacher;
use App\Domains\Auth\Models\TeacherVerificationCheck;
use App\Domains\Auth\Repositories\TeacherRepositoryInterface;
use App\Shared\Mail\QueuedMailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * POST /auth/register — self-service sign-up for parents, independent
 * students and teachers (see RegisterRequest's docblock for why not a
 * child or admin).
 */
class RegistrationHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'account_type' => 'parent',
            'email' => 'new-parent@example.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $overrides);
    }

    public function test_registering_returns_a_working_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()->assertJsonStructure(['token']);

        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('role', 'parent')
            ->assertJsonPath('name', 'new-parent@example.test');
    }

    public function test_a_registered_account_can_log_in_with_its_password(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'new-parent@example.test',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_an_independent_student_can_register(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['account_type' => 'independent_student']))
            ->assertCreated();

        $this->assertSame(
            'independent_student',
            Account::where('email', 'new-parent@example.test')->value('account_type'),
        );
    }

    public function test_the_password_is_stored_hashed(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $hash = Account::where('email', 'new-parent@example.test')->value('password_hash');

        $this->assertNotSame('secret123', $hash);
        $this->assertTrue(password_verify('secret123', $hash));
    }

    #[DataProvider('nonSelfServiceAccountTypes')]
    public function test_account_types_that_cannot_self_register_are_rejected(string $accountType): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['account_type' => $accountType]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['account_type']]]);

        $this->assertFalse(Account::where('email', 'new-parent@example.test')->exists());
    }

    public static function nonSelfServiceAccountTypes(): array
    {
        return [['admin'], ['child']];
    }

    public function test_a_teacher_registers_as_a_pending_applicant(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload([
            'account_type' => 'teacher',
            'email' => 'new-teacher@example.test',
            'name' => 'Amaka Obi',
        ]));

        $response->assertCreated();

        $teacher = Account::where('email', 'new-teacher@example.test')->firstOrFail()->teacher;

        $this->assertSame('Amaka Obi', $teacher->full_name);
        $this->assertSame('pending', $teacher->verification_status);
        $this->assertNull($teacher->rate_minor);
        $this->assertEqualsCanonicalizing(
            TeacherVerificationCheck::KINDS,
            $teacher->verificationChecks()->where('state', 'pending')->pluck('kind')->all(),
        );

        // The session's teacher_id is what the onboarding wizard PATCHes.
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('role', 'teacher')
            ->assertJsonPath('teacher_id', $teacher->public_id);
    }

    public function test_a_teacher_registration_writes_a_teacher_registered_outbox_event(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'account_type' => 'teacher',
            'email' => 'new-teacher@example.test',
            'name' => 'Amaka Obi',
        ]))->assertCreated();

        $teacher = Account::where('email', 'new-teacher@example.test')->firstOrFail()->teacher;
        $event = OutboxEvent::where('event_type', 'TeacherRegistered')->sole();

        $this->assertEquals($teacher->id, $event->aggregate_id);
        $this->assertSame($teacher->public_id, $event->payload['public_id']);
        $this->assertEquals($teacher->account_id, $event->payload['account_id']);
        $this->assertNull($event->published_at);
    }

    public function test_a_newly_registered_teacher_can_complete_their_profile(): void
    {
        $token = $this->postJson('/api/v1/auth/register', $this->payload([
            'account_type' => 'teacher',
            'email' => 'new-teacher@example.test',
            'name' => 'Amaka Obi',
        ]))->assertCreated()->json('token');

        $teacherId = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/session')
            ->json('teacher_id');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/teachers/{$teacherId}", [
                'years_teaching' => 4,
                'format' => 'one_on_one',
                'price_per_session_minor' => 450000,
            ])
            ->assertOk()
            ->assertJsonPath('data.price_per_session_minor', 450000);
    }

    public function test_a_teacher_must_give_a_name(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['account_type' => 'teacher']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['name']]]);

        $this->assertFalse(Account::where('email', 'new-parent@example.test')->exists());
    }

    public function test_a_teacher_name_with_contact_details_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'account_type' => 'teacher',
            'name' => 'Amaka 08012345678',
        ]))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name']]]);
    }

    public function test_a_parent_registration_creates_no_teacher_row_or_event(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['name' => 'Ignored For Parents']))->assertCreated();

        $this->assertNull(Account::where('email', 'new-parent@example.test')->firstOrFail()->teacher);
        $this->assertFalse(OutboxEvent::where('event_type', 'TeacherRegistered')->exists());
    }

    public function test_a_welcome_email_is_queued_to_the_new_account(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        Mail::assertQueued(WelcomeToTewtora::class, fn (WelcomeToTewtora $mail) => $mail->hasTo('new-parent@example.test')
            && $mail->account->account_type === 'parent'
            && $mail->name === null);
        Mail::assertQueuedCount(1);
    }

    public function test_a_teachers_welcome_email_carries_their_name(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', $this->payload([
            'account_type' => 'teacher',
            'email' => 'new-teacher@example.test',
            'name' => 'Amaka Obi',
        ]))->assertCreated();

        Mail::assertQueued(WelcomeToTewtora::class, fn (WelcomeToTewtora $mail) => $mail->hasTo('new-teacher@example.test')
            && $mail->name === 'Amaka Obi');
    }

    /** Registration only pushes the job — sending, retries and throttling happen in the `mail` queue worker. */
    public function test_registration_pushes_the_welcome_email_onto_the_mail_queue(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        Queue::assertPushedOn(QueuedMailable::QUEUE, SendQueuedMailable::class, fn (SendQueuedMailable $job) => $job->mailable instanceof WelcomeToTewtora);
    }

    public function test_no_welcome_email_is_sent_when_registration_fails_validation(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', $this->payload(['password_confirmation' => 'different123']))
            ->assertStatus(422);

        Mail::assertNothingQueued();
    }

    /** The account exists by the time mail is queued — a Resend/queue outage must not turn sign-up into a 500. */
    public function test_registration_still_succeeds_when_the_welcome_email_cannot_be_queued(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Resend is down'));

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['token']);

        $this->assertTrue(Account::where('email', 'new-parent@example.test')->exists());
    }

    /** All-or-nothing: if the login token can't be created, the account must not survive behind the 500. */
    public function test_no_account_is_left_behind_when_the_token_cannot_be_created(): void
    {
        Mail::fake();
        PersonalAccessToken::creating(fn () => throw new RuntimeException('token table unavailable'));

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'internal_error');

        $this->assertFalse(Account::where('email', 'new-parent@example.test')->exists());
        Mail::assertNothingQueued();
    }

    public function test_no_account_is_left_behind_when_the_teacher_profile_cannot_be_created(): void
    {
        $this->mock(TeacherRepositoryInterface::class)
            ->shouldReceive('createApplicant')
            ->andThrow(new RuntimeException('teachers table unavailable'));

        $this->postJson('/api/v1/auth/register', $this->payload([
            'account_type' => 'teacher',
            'email' => 'new-teacher@example.test',
            'name' => 'Amaka Obi',
        ]))->assertStatus(500);

        $this->assertFalse(Account::where('email', 'new-teacher@example.test')->exists());
    }

    public function test_no_account_or_teacher_is_left_behind_when_the_outbox_event_cannot_be_written(): void
    {
        OutboxEvent::creating(fn () => throw new RuntimeException('outbox unavailable'));

        $this->postJson('/api/v1/auth/register', $this->payload([
            'account_type' => 'teacher',
            'email' => 'new-teacher@example.test',
            'name' => 'Amaka Obi',
        ]))->assertStatus(500);

        $this->assertFalse(Account::where('email', 'new-teacher@example.test')->exists());
        $this->assertSame(0, Teacher::count());
    }

    /** Once the account and token are committed, a broken log can't turn the sign-up into an error. */
    public function test_a_logging_failure_after_commit_does_not_fail_the_sign_up(): void
    {
        // The real production failure: the auth log can't be written (e.g.
        // storage/logs owned by the wrong user), so Monolog throws on write.
        config(['logging.channels.auth.path' => '/proc/tewtora-unwritable/auth.log']);
        Log::forgetChannel('auth');

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['token']);

        $this->assertTrue(Account::where('email', 'new-parent@example.test')->exists());
    }

    /** The formats people (and number inputs) actually send are cleaned to +digits and stored that way. */
    #[DataProvider('acceptedPhones')]
    public function test_common_phone_formats_are_accepted_and_normalised(string|int $sent, string $stored): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['phone' => $sent]))->assertCreated();

        $this->assertSame($stored, Account::where('email', 'new-parent@example.test')->value('phone'));
    }

    public static function acceptedPhones(): array
    {
        return [
            'international' => ['+2348012345678', '+2348012345678'],
            'local' => ['08012345678', '08012345678'],
            'spaced' => ['+234 801 234 5678', '+2348012345678'],
            'brackets and dashes' => ['+234 (0) 801-234-5678', '+23408012345678'],
            'JSON number' => [2348012345678, '2348012345678'],
        ];
    }

    public function test_phone_is_optional(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['phone' => '']))->assertCreated();

        $this->assertNull(Account::where('email', 'new-parent@example.test')->value('phone'));
    }

    #[DataProvider('rejectedPhones')]
    public function test_something_that_is_not_a_phone_number_is_rejected(string $sent): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['phone' => $sent]))
            ->assertStatus(422)
            ->assertJsonPath('error.fields.phone.0', 'Enter a valid phone number, for example +2348012345678.');

        $this->assertFalse(Account::where('email', 'new-parent@example.test')->exists());
    }

    public static function rejectedPhones(): array
    {
        return [
            'letters' => ['call me'],
            'too short' => ['12345'],
            'too long' => ['+1234567890123456'],
            'plus in the middle' => ['234+8012345678'],
        ];
    }

    public function test_an_email_already_in_use_is_rejected(): void
    {
        $this->makeAccount(['email' => 'new-parent@example.test']);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['email']]]);
    }

    public function test_a_soft_deleted_accounts_email_can_be_registered_again(): void
    {
        $this->makeAccount(['email' => 'new-parent@example.test'])->delete();

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();
    }

    public function test_a_mismatched_password_confirmation_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['password_confirmation' => 'different123']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['password']]]);
    }

    public function test_a_short_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['password']]]);
    }
}
