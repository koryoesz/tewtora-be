<?php

namespace App\Domains\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrialRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'slot_starts_at' => $this->slot_starts_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'session_id' => $this->whenNotNull($this->session?->public_id),
        ];
    }
}
