<?php

namespace App\Domains\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'actor_id' => $this->actor?->public_id,
            'actor_name' => $this->actor?->email,
            'action' => $this->action,
            'subject_id' => $this->subject_id,
            'reason' => $this->reason,
            'at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
