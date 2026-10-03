<?php

namespace App\Domains\Core\Http\Resources;

use App\Domains\Core\Models\TeacherAccountLink;
use App\Domains\Core\Support\TrialDeclineReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrialRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isTeacherSide = $this->viewerIsTeacherSide($request);

        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'slot_starts_at' => $this->slot_starts_at?->toIso8601String(),
            // Set only once the teacher counters a 'pending' request — the
            // family then responds against this, not slot_starts_at above
            // (TrialRequestService::accept/decline).
            'countered_starts_at' => $this->countered_starts_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            // The fixed-enum code is harmless either side; the wording next
            // to it is audience-dependent — the teacher's own candid
            // version never reaches this same field on the family's read of
            // the same request (TrialDeclineReason's docblock).
            'decline_reason' => $this->decline_reason,
            'decline_reason_message' => $this->when($this->decline_reason !== null, fn () => $isTeacherSide
                ? TrialDeclineReason::teacherWording($this->decline_reason)
                : TrialDeclineReason::familyWording($this->decline_reason)
            ),
            // Null until the teacher accepts/declines/counters — never set
            // by a requester's own cancel.
            'responded_at' => $this->responded_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'session_id' => $this->whenNotNull($this->session?->public_id),
        ];
    }

    private function viewerIsTeacherSide(Request $request): bool
    {
        $account = $request->user();

        if (! $account) {
            return false;
        }

        return TeacherAccountLink::where('teacher_id', $this->teacher_id)
            ->where('account_id', $account->id)
            ->exists();
    }
}
