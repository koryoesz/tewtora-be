<?php

namespace App\Domains\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately no teacher/learner display name — same precedent as
 * PlanResource, which doesn't surface one either. The frontend already
 * knows who's who from GET /learners and GET /teachers/{id}; this only
 * needs to carry the ids to correlate against those.
 */
class MessageThreadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'plan_id' => $this->whenLoaded('plan', fn () => $this->plan?->public_id),
            'learner_id' => $this->whenLoaded('learnerAccountLink', fn () => $this->learnerAccountLink?->public_id),
            'teacher_id' => $this->whenLoaded('teacherAccountLink', fn () => $this->teacherAccountLink?->public_id),
            'is_support' => $this->is_support,
            // Set by the controller per-viewer before wrapping — not a column, never trust a stale/cached value here.
            'unread_count' => $this->unread_count ?? 0,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
        ];
    }
}
