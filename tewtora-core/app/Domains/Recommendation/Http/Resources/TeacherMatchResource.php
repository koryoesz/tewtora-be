<?php

namespace App\Domains\Recommendation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * docs/api-contract.md §9: the response shape itself must carry no
 * name/photo/contact field for the requesting learner — enforced here by
 * what's actually selected, not by the frontend choosing not to render it.
 * Identity only becomes visible through a different endpoint once accepted.
 */
class TeacherMatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'match_reasoning' => $this->match_reasoning,
            'decline_reason' => $this->decline_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
