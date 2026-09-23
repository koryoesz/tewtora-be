<?php

namespace App\Domains\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedbackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // feedback has no public_id — it's always reached nested under a
        // session (docs/api-contract.md §11's routes are all
        // /sessions/:id/feedback), never looked up by its own id, so
        // CLAUDE.md's "never expose id externally" rule is honored by
        // simply not exposing one, rather than adding an unused column.
        return [
            'status' => $this->status,
            'attendance' => $this->attendance,
            'session_notes' => $this->session_notes,
            'progress_rating' => $this->progress_rating,
            'next_steps' => $this->next_steps,
            'submitted_at' => $this->status === 'submitted' ? $this->submitted_at?->toIso8601String() : null,
        ];
    }
}
