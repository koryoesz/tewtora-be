<?php

namespace Tests\Feature\Auth;

use App\Domains\Auth\Mail\PasswordChanged;
use App\Domains\Auth\Mail\PasswordResetLink;
use App\Domains\Auth\Models\Account;
use App\Shared\Mail\QueuedMailable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Tests\TestCase;

/** Rendering and queue settings only — no database, same approach as WelcomeEmailTest. */
class PasswordEmailsTest extends TestCase
{
    private function parent(): Account
    {
        return (new Account)->forceFill(['account_type' => 'parent', 'email' => 'ada+kids@example.test']);
    }

    public function test_the_reset_button_links_to_the_frontend_with_the_token_and_encoded_email(): void
    {
        config(['app.frontend_url' => 'https://app.tewtora.test']);

        (new PasswordResetLink($this->parent(), 'plain-token-123'))
            ->assertSeeInHtml('https://app.tewtora.test/reset-password?token=plain-token-123&amp;email=ada%2Bkids%40example.test', false)
            ->assertSeeInText('https://app.tewtora.test/reset-password?token=plain-token-123&email=ada%2Bkids%40example.test');
    }

    public function test_the_reset_email_states_the_real_expiry(): void
    {
        config(['auth.passwords.users.expire' => 45]);

        (new PasswordResetLink($this->parent(), 'plain-token-123'))
            ->assertSeeInHtml('45 minutes')
            ->assertSeeInText('45 minutes')
            ->assertHasSubject('Reset your Tewtora password');
    }

    /** The plain token is a live credential, so it must not sit readable in the jobs table. */
    public function test_the_queued_reset_email_is_encrypted_and_stops_retrying_when_the_link_expires(): void
    {
        config(['auth.passwords.users.expire' => 60]);
        $mail = new PasswordResetLink($this->parent(), 'plain-token-123');

        $this->assertInstanceOf(ShouldBeEncrypted::class, $mail);
        $this->assertSame(QueuedMailable::QUEUE, $mail->queue);
        $this->assertTrue($mail->retryUntil() <= now()->addMinutes(60));
    }

    public function test_the_changed_notice_shows_when_and_how_to_react(): void
    {
        config(['app.frontend_url' => 'https://app.tewtora.test', 'app.timezone' => 'UTC']);
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(17, 7));

        (new PasswordChanged($this->parent()))
            ->assertHasSubject('Your Tewtora password was changed')
            ->assertSeeInHtml('31 Oct 2026, 17:07 UTC')
            ->assertSeeInHtml('href="https://app.tewtora.test/login"', false)
            ->assertSeeInHtml('href="https://app.tewtora.test/forgot-password"', false)
            ->assertSeeInText("WASN'T YOU?");
    }

    public function test_plain_text_parts_are_not_html_escaped(): void
    {
        (new PasswordResetLink($this->parent(), 'plain-token-123'))->assertDontSeeInText('&#039;')->assertDontSeeInText('&amp;');
        (new PasswordChanged($this->parent()))->assertDontSeeInText('&#039;');
    }
}
