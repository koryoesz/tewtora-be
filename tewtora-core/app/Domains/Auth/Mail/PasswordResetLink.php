<?php

namespace App\Domains\Auth\Mail;

use App\Domains\Auth\Models\Account;
use App\Shared\Mail\QueuedMailable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * POST /auth/forgot-password (PasswordResetService::sendResetLink).
 *
 * The plain reset token is a live credential until it's used or expires,
 * so the queued job payload is encrypted (ShouldBeEncrypted) rather than
 * sitting readable in the jobs table, and delivery stops retrying once the
 * link would have expired anyway.
 */
class PasswordResetLink extends QueuedMailable implements ShouldBeEncrypted
{
    use GreetsAccount;

    protected string $logChannel = 'auth';

    public function __construct(
        public readonly Account $account,
        #[\SensitiveParameter] public readonly string $token,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Tewtora password');
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth-mail::password-reset',
            text: 'auth-mail::password-reset-text',
            with: [
                'greeting' => $this->greetingFor($this->account),
                'email' => $this->account->email,
                'expiresInMinutes' => $this->expiresInMinutes(),
                'resetUrl' => rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
                    'token' => $this->token,
                    'email' => $this->account->email,
                ]),
            ],
        );
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes($this->expiresInMinutes());
    }

    protected function failureContext(): array
    {
        return ['account_id' => $this->account->public_id];
    }

    private function expiresInMinutes(): int
    {
        return (int) config('auth.passwords.users.expire');
    }
}
