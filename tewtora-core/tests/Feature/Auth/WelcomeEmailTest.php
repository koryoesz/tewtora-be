<?php

namespace Tests\Feature\Auth;

use App\Domains\Auth\Mail\PasswordChanged;
use App\Domains\Auth\Mail\PasswordResetLink;
use App\Domains\Auth\Mail\WelcomeToTewtora;
use App\Domains\Auth\Models\Account;
use App\Shared\Mail\QueuedMailable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

/**
 * Rendering only — no database: the Mailable only reads account_type and
 * email, so an unsaved Account is enough.
 */
class WelcomeEmailTest extends TestCase
{
    private function mailFor(string $accountType, ?string $name = null): WelcomeToTewtora
    {
        return new WelcomeToTewtora(
            (new Account)->forceFill(['account_type' => $accountType, 'email' => 'someone@example.test']),
            $name,
        );
    }

    public function test_the_button_links_to_the_frontend_dashboard(): void
    {
        config(['app.frontend_url' => 'https://app.tewtora.test/']);

        $this->mailFor('parent')->assertSeeInHtml('href="https://app.tewtora.test/dashboard"', false);
    }

    public function test_links_default_to_the_production_app(): void
    {
        $this->mailFor('parent')->assertSeeInHtml('href="https://app.tewtora.com/dashboard"', false);
    }

    /** Emails follow the frontend's brand green (MailTheme), with no leftover off-brand colour. */
    public function test_every_email_uses_the_frontend_brand_colour(): void
    {
        foreach ([
            $this->mailFor('teacher', 'Amaka Obi'),
            new PasswordResetLink($this->mailFor('parent')->account, 'token'),
            new PasswordChanged($this->mailFor('parent')->account),
        ] as $mail) {
            $mail->assertSeeInHtml('#1BAA6E', false)->assertDontSeeInHtml('#5B6CFF', false);
        }
    }

    public function test_a_teacher_is_greeted_by_first_name_with_teacher_next_steps(): void
    {
        $mail = $this->mailFor('teacher', 'Amaka Obi');

        $mail->assertSeeInHtml('Hi Amaka,')
            ->assertSeeInHtml('Complete your teaching profile')
            ->assertSeeInHtml('Get verified')
            ->assertDontSeeInHtml("Add your child's profile");
        $mail->assertHasSubject("Welcome to Tewtora — we're so glad you're here");
    }

    public function test_a_parent_gets_parent_next_steps_and_a_generic_greeting(): void
    {
        $this->mailFor('parent')
            ->assertSeeInHtml('Hi there,')
            ->assertSeeInHtml("Add your child's profile")
            ->assertDontSeeInHtml('Get verified');
    }

    public function test_an_independent_student_gets_their_own_next_steps(): void
    {
        $this->mailFor('independent_student')
            ->assertSeeInHtml('Set up your learner profile')
            ->assertDontSeeInHtml("Add your child's profile");
    }

    public function test_the_plain_text_part_is_not_html_escaped(): void
    {
        $this->mailFor('teacher', 'Amaka Obi')
            ->assertSeeInText("we're genuinely excited")
            ->assertDontSeeInText('&#039;');
    }

    public function test_the_mail_is_queued_only_after_the_registration_commits(): void
    {
        $this->assertTrue($this->mailFor('parent')->afterCommit);
    }

    public function test_it_goes_on_the_dedicated_mail_queue(): void
    {
        $this->assertSame(QueuedMailable::QUEUE, $this->mailFor('parent')->queue);
    }

    public function test_send_errors_are_retried_with_growing_backoff_inside_a_time_window(): void
    {
        $mail = $this->mailFor('parent');

        $this->assertSame(5, $mail->maxExceptions);
        $this->assertSame([10, 60, 300, 900, 1800], $mail->backoff());
        $this->assertTrue($mail->retryUntil() > now()->addHours(5));
    }

    public function test_it_is_throttled_to_the_resend_rate_limit_on_a_real_queue(): void
    {
        config(['queue.default' => 'database', 'services.resend.rate_limit_per_second' => 4]);

        $middleware = $this->mailFor('parent')->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
        $this->assertSame(4, RateLimiter::limiter('mail')()->maxAttempts);
    }

    /** A sync job can't be released, so throttling there would drop the email rather than delay it. */
    public function test_it_is_not_throttled_on_the_sync_queue(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertSame([], $this->mailFor('parent')->middleware());
    }

    public function test_a_final_failure_is_logged_to_the_auth_channel_without_the_email_address(): void
    {
        $account = (new Account)->forceFill(['account_type' => 'teacher', 'email' => 'someone@example.test', 'public_id' => 'acct-uuid']);

        Log::shouldReceive('channel')->once()->with('auth')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(fn (string $message, array $context) => $message === 'email could not be sent'
            && $context['mailable'] === WelcomeToTewtora::class
            && $context['account_id'] === 'acct-uuid'
            && ! str_contains(json_encode(array_diff_key($context, ['exception' => 1])), 'someone@example.test'));

        (new WelcomeToTewtora($account))->failed(new RuntimeException('Resend rejected the request'));
    }
}
