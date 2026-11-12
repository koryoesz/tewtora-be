<?php

namespace App\Domains\Core\Mail;

use App\Domains\Core\Models\ClassEnrollment;
use App\Shared\Mail\QueuedMailable;
use App\Shared\Support\Money;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to the payer once a seat is confirmed: payment received (or a free
 * class) and the schedule. Doubles as the receipt. Deliberately no meeting
 * link: links are per class day and only revealed shortly before each one
 * (TewtoraClassSession::linkIsRevealable), so the button opens the class page.
 * Formatting of money and times happens here, at render time only.
 */
class ClassEnrollmentConfirmed extends QueuedMailable
{
    protected string $logChannel = 'core';

    public function __construct(
        public readonly ClassEnrollment $enrollment,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're in: {$this->enrollment->tewtoraClass->title}");
    }

    public function content(): Content
    {
        $class = $this->enrollment->tewtoraClass;
        $tz = config('classes.display_timezone');

        return new Content(
            view: 'core-mail::class-enrollment-confirmed',
            text: 'core-mail::class-enrollment-confirmed-text',
            with: [
                'classTitle' => $class->title,
                'isFree' => $this->enrollment->amount_minor === 0,
                'amountLabel' => Money::format($this->enrollment->amount_minor, $this->enrollment->currency_code),
                'reference' => $this->enrollment->provider_reference,
                'sessionLabels' => $class->sessions
                    ->map(fn ($s) => "Day {$s->day_number} · ".$s->starts_at->setTimezone($tz)->format('D j M, H:i').' – '.$s->ends_at->setTimezone($tz)->format('H:i'))
                    ->all(),
                'timezoneLabel' => 'Lagos time',
                'classUrl' => rtrim(config('app.frontend_url'), '/')."/classes/{$class->public_id}",
            ],
        );
    }

    protected function failureContext(): array
    {
        return ['enrollment_id' => $this->enrollment->public_id];
    }
}
