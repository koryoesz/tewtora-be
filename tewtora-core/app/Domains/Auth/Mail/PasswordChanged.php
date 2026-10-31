<?php

namespace App\Domains\Auth\Mail;

use App\Domains\Auth\Models\Account;
use App\Shared\Mail\QueuedMailable;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Security notice after a successful POST /auth/reset-password
 * (PasswordResetService::reset), so an account owner whose password was
 * changed by someone else finds out and can take it back.
 */
class PasswordChanged extends QueuedMailable
{
    use GreetsAccount;

    protected string $logChannel = 'auth';

    /** When the change happened, not when the queued email is finally sent. */
    public readonly CarbonImmutable $changedAt;

    public function __construct(
        public readonly Account $account,
    ) {
        parent::__construct();

        $this->changedAt = CarbonImmutable::now();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Tewtora password was changed');
    }

    public function content(): Content
    {
        $frontend = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'auth-mail::password-changed',
            text: 'auth-mail::password-changed-text',
            with: [
                'greeting' => $this->greetingFor($this->account),
                'email' => $this->account->email,
                // Not 'changedAt': a public property's name would be overwritten by
                // the raw property when Laravel builds the view data.
                'changedAtLabel' => $this->changedAt->setTimezone(config('app.timezone'))->format('j M Y, H:i T'),
                'loginUrl' => $frontend.'/login',
                'forgotUrl' => $frontend.'/forgot-password',
            ],
        );
    }

    protected function failureContext(): array
    {
        return ['account_id' => $this->account->public_id];
    }
}
