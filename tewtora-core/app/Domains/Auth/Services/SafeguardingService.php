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

    public function close(SafeguardingIncident $incident, string $note): SafeguardingIncident
    {
        $incident->update(['status' => 'closed', 'closed_note' => $note, 'closed_at' => now()]);

        $this->audit('closed', 'safeguarding_incident', (string) $incident->public_id, $note);

        return $incident;
    }
}
