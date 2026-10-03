<?php

namespace App\Domains\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanHistoryEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $feedback = $this->whenLoaded('feedback') ?: null;

        return [
            'id' => $this->public_id,
            // Same teacher-aggregate need as PlanNextSessionResource — only
            // present when `plan` was eager-loaded.
            'plan_id' => $this->whenLoaded('plan', fn () => $this->plan?->public_id),
            'session_date' => $this->scheduled_at?->toIso8601String(),
            'status' => $this->status,
            'score_out_of_5' => $feedback && $feedback->status === 'submitted' ? $feedback->progress_rating : null,
            'note' => $feedback?->session_notes,
            'next_steps' => $feedback?->next_steps,
        ];
    }
}
