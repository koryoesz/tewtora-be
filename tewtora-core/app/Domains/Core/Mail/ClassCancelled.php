<?php

namespace App\Domains\Core\Mail;

use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Models\TewtoraClass;
use App\Shared\Mail\QueuedMailable;
use App\Shared\Support\Money;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to each family that had a confirmed seat when an admin cancels a
 * class. No refunds in v1, so for a paid seat it says the team will be in
 * touch about the payment rather than promising a refund.
 */
class ClassCancelled extends QueuedMailable
{
    protected string $logChannel = 'core';

    public function __construct(
        public readonly TewtoraClass $tewtoraClass,
        public readonly ClassEnrollment $enrollment,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Class cancelled: {$this->tewtoraClass->title}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'core-mail::class-cancelled',
            text: 'core-mail::class-cancelled-text',
            with: [
                'classTitle' => $this->tewtoraClass->title,
                'reason' => $this->tewtoraClass->cancellation_reason,
                'wasPaid' => $this->enrollment->paid_at !== null,
                'amountLabel' => Money::format($this->enrollment->amount_minor, $this->enrollment->currency_code),
                'browseUrl' => rtrim(config('app.frontend_url'), '/').'/classes',
            ],
        );
    }

    protected function failureContext(): array
    {
        return ['enrollment_id' => $this->enrollment->public_id];
    }
}
