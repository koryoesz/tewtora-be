<?php

namespace App\Domains\Auth\Mail;

use App\Domains\Auth\Models\Account;
use App\Shared\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent once after POST /auth/register (AuthSessionService::register).
 * Queue name, retries, rate limiting and failure logging all come from
 * QueuedMailable. A custom HTML view on the shared brand layout rather than
 * a Markdown mailable: the Markdown theme can't do the hero panel or step
 * cards.
 *
 * Copy is per account type: a teacher is a pending applicant whose next
 * step is finishing their profile and verification, not finding a tutor.
 */
class WelcomeToTewtora extends QueuedMailable
{
    use GreetsAccount;

    protected string $logChannel = 'auth';

    /** @param  ?string  $name  Only a teacher gives one at sign-up — accounts have no name column. */
    public function __construct(
        public readonly Account $account,
        public readonly ?string $name = null,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->copy()['subject']);
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth-mail::welcome',
            text: 'auth-mail::welcome-text',
            with: [
                'copy' => $this->copy(),
                'greeting' => $this->greetingFor($this->account, $this->name),
                'initial' => mb_strtoupper(mb_substr($this->firstNameOf($this->account, $this->name) ?? $this->account->email, 0, 1)),
                'email' => $this->account->email,
                'dashboardUrl' => rtrim(config('app.frontend_url'), '/').'/dashboard',
            ],
        );
    }

    protected function failureContext(): array
    {
        return ['account_id' => $this->account->public_id, 'account_type' => $this->account->account_type];
    }

    /** @return array<string, mixed> */
    public function copy(): array
    {
        return match ($this->account->account_type) {
            'teacher' => [
                'subject' => "Welcome to Tewtora — we're so glad you're here",
                'preheader' => "Your teacher account is ready. Here's how to get your profile in front of learners.",
                'eyebrow' => 'Welcome aboard',
                'headline' => "We're so glad you're teaching with us",
                'subline' => 'Your account is ready. A few short steps and families will be able to find you.',
                'card_title' => 'Your teaching profile',
                'card_caption' => 'Step 1 of 3 · Account created',
                'card_badge' => 'In progress',
                'intro' => [
                    "Thank you for joining Tewtora. Great teachers are the heart of everything we do, and we're genuinely excited to have you with us.",
                    "Your dashboard is ready whenever you are. Here's what happens next:",
                ],
                'steps_heading' => 'Your next steps',
                'steps' => [
                    ['title' => 'Complete your teaching profile', 'body' => 'Add the subjects, curricula and levels you teach, your rate, your availability and a short bio.'],
                    ['title' => 'Get verified', 'body' => 'We confirm your ID and credentials and run a short teaching demo. This keeps every family safe.'],
                    ['title' => 'Start meeting learners', 'body' => "Once you're approved, we match you with learners who fit what you teach."],
                ],
                'cta' => 'Continue to your dashboard',
                'signoff' => "We can't wait to see you in the classroom.",
            ],
            'independent_student' => [
                'subject' => "Welcome to Tewtora — we're so glad you're here",
                'preheader' => "Your account is ready. Let's find the right teacher for you.",
                'eyebrow' => 'Welcome to Tewtora',
                'headline' => "We're so glad you're here",
                'subline' => "Your account is ready. Let's find a teacher who fits how you learn.",
                'card_title' => 'Your learning plan',
                'card_caption' => 'Step 1 of 3 · Account created',
                'card_badge' => 'Getting started',
                'intro' => [
                    "Thank you for choosing Tewtora. We're excited to be part of your learning journey.",
                    'Your dashboard is ready whenever you are. Here\'s how to get started:',
                ],
                'steps_heading' => 'Your next steps',
                'steps' => [
                    ['title' => 'Set up your learner profile', 'body' => 'Tell us your level and curriculum so we can tailor everything to you.'],
                    ['title' => 'Share your goals', 'body' => 'A short assessment covers the subjects, goals and times that work for you.'],
                    ['title' => 'Meet your teachers', 'body' => 'We match you with verified teachers. Start with a trial lesson.'],
                ],
                'cta' => 'Continue to your dashboard',
                'signoff' => "We're cheering you on.",
            ],
            default => [
                'subject' => "Welcome to Tewtora — we're so glad you're here",
                'preheader' => "Your account is ready. Let's find the right teacher for your child.",
                'eyebrow' => 'Welcome to Tewtora',
                'headline' => "We're so glad you're here",
                'subline' => 'Your account is ready. Let\'s find a teacher your child will love learning with.',
                'card_title' => "Your child's learning plan",
                'card_caption' => 'Step 1 of 3 · Account created',
                'card_badge' => 'Getting started',
                'intro' => [
                    "Thank you for trusting Tewtora with your child's learning. We're delighted to have your family with us.",
                    'Your dashboard is ready whenever you are. Here\'s how to get started:',
                ],
                'steps_heading' => 'Your next steps',
                'steps' => [
                    ['title' => "Add your child's profile", 'body' => 'Their level and curriculum help us find the right fit. You can add more than one child.'],
                    ['title' => 'Tell us what they need', 'body' => 'A short assessment covers the subjects, goals and lesson times that work for your family.'],
                    ['title' => 'Meet verified teachers', 'body' => 'We match you with teachers who have been verified, then you book a trial lesson.'],
                ],
                'cta' => 'Continue to your dashboard',
                'signoff' => "We're looking forward to helping your child grow.",
            ],
        };
    }
}
