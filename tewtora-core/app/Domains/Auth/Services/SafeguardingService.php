<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Models\SafeguardingIncident;
use App\Domains\Auth\Models\Teacher;
use App\Shared\Logging\Auditable;

class SafeguardingService
{
    use Auditable;

    public function open()
    {
        return SafeguardingIncident::where('status', 'open')->orderByDesc('reported_at')->get();
    }

    public function view(SafeguardingIncident $incident): SafeguardingIncident
    {
        $this->audit('viewed', 'safeguarding_incident', (string) $incident->public_id);

        return $incident;
    }

    /** docs/api-contract.md §17: scoped to new matches only — existing lessons must keep running. */
    public function suspendNewMatches(Teacher $teacher): Teacher
    {
        $before = $teacher->only('new_matches_suspended_at');

        $teacher->update(['new_matches_suspended_at' => now()]);

        $this->audit('suspended', 'teacher', (string) $teacher->public_id, null, $before, $teacher->only('new_matches_suspended_at'));

        return $teacher;
    }

    /**
     * The cross-domain entry point Core's message-reporting flow calls
     * (App\Domains\Core\Http\Controllers\MessageThreadController::report)
     * rather than reaching into this table's Eloquent model directly —
     * CLAUDE.md's cross-domain hard rule. Synchronous, not outbox-relayed:
     * the whole point of "you don't need to explain it twice" is that the
     * incident is visible in the admin queue immediately, not after a
     * relay delay.
     */
    public function reportConcern(int $learnerProfileId, int $teacherId, int $reportedByAccountId, string $summary, string $severity = 'medium'): SafeguardingIncident
    {
        // public_id is DB-generated (DEFAULT (UUID())) — create()'s in-memory
        // model doesn't know it without a refresh, which the audit() call
        // right below needs (a blank subject_id fails audit_log's own
        // non-empty CHECK).
        $incident = SafeguardingIncident::create([
            'learner_profile_id' => $learnerProfileId,
            'teacher_id' => $teacherId,
            'reported_by_account_id' => $reportedByAccountId,
            'severity' => $severity,
            'summary' => $summary,
        ])->refresh();

        $this->audit('reported', 'safeguarding_incident', (string) $incident->public_id, $summary);

        return $incident;
    }

    public function close(SafeguardingIncident $incident, string $note): SafeguardingIncident
    {
        $incident->update(['status' => 'closed', 'closed_note' => $note, 'closed_at' => now()]);

        $this->audit('closed', 'safeguarding_incident', (string) $incident->public_id, $note);

        return $incident;
    }
}
