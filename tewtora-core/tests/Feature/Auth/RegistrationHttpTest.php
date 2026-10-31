<?php

namespace Tests\Feature\Auth;

use App\Domains\Auth\Mail\WelcomeToTewtora;
use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\OutboxEvent;
use App\Domains\Auth\Models\TeacherVerificationCheck;
use App\Shared\Mail\QueuedMailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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
