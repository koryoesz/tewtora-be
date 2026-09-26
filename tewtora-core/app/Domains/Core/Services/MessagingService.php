<?php

namespace App\Domains\Core\Services;

use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\SafeguardingIncident;
use App\Domains\Auth\Services\SafeguardingService;
use App\Domains\Core\Exceptions\SupportThreadCannotBeReportedException;
use App\Domains\Core\Models\Message;
use App\Domains\Core\Models\MessageRead;
use App\Domains\Core\Models\MessageThread;
use App\Domains\Core\Repositories\MessageRepositoryInterface;
use App\Domains\Core\Repositories\MessageThreadRepositoryInterface;

class MessagingService
{
    public function __construct(
        private readonly MessageThreadRepositoryInterface $threads,
        private readonly MessageRepositoryInterface $messages,
        private readonly MessageRedactor $redactor,
        private readonly SafeguardingService $safeguarding,
    ) {}

    public function sendMessage(MessageThread $thread, Account $sender, string $text): Message
    {
        ['body' => $body, 'redacted' => $redacted] = $this->redactor->redact($text);

        // public_id/created_at are DB-generated (DEFAULT (UUID())/
        // CURRENT_TIMESTAMP(6)) — the in-memory model from create() doesn't
        // know either without a refresh.
        $message = $this->messages->create([
            'thread_id' => $thread->id,
            'sender_account_id' => $sender->id,
            'sender_role' => $sender->account_type,
            'body' => $body,
            'redacted' => $redacted,
        ])->refresh();

        $this->threads->touchLastMessageAt($thread);

        return $message;
    }

    /** Only ever called from an explicit "mark read" action — never a side effect of viewing/listing. */
    public function markRead(MessageThread $thread, Account $account): void
    {
        MessageRead::updateOrCreate(
            ['thread_id' => $thread->id, 'account_id' => $account->id],
            ['last_read_at' => now()],
        );
    }

    public function unreadCountFor(MessageThread $thread, Account $account): int
    {
        $lastReadAt = MessageRead::where('thread_id', $thread->id)
            ->where('account_id', $account->id)
            ->value('last_read_at');

        return $this->messages->countSince($thread, $lastReadAt);
    }

    /**
     * Creates a real SafeguardingIncident via Auth's own service — never
     * touches auth.safeguarding_incidents' Eloquent model directly
     * (CLAUDE.md's cross-domain hard rule). Severity defaults to 'medium'
     * (matching the existing parent-reported pattern); this is a triage
     * default, not a judgment on any specific report's actual severity.
     */
    public function reportConcern(MessageThread $thread, Account $reporter): SafeguardingIncident
    {
        if ($thread->is_support) {
            throw new SupportThreadCannotBeReportedException;
        }

        $summary = sprintf(
            'Concern reported from a message thread by a %s (account #%d). Thread: learner #%d, teacher #%d.',
            $reporter->account_type,
            $reporter->id,
            $thread->learner_profile_id,
            $thread->teacher_id,
        );

        return $this->safeguarding->reportConcern(
            learnerProfileId: $thread->learner_profile_id,
            teacherId: $thread->teacher_id,
            reportedByAccountId: $reporter->id,
            summary: $summary,
        );
    }
}
