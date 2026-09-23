<?php

namespace App\Domains\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MoveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'kind' => $this->kind,
            'route' => $this->route,
            'reason' => $this->reason,
            'from' => ['day' => $this->from_day, 'starts_at' => $this->from_starts_at],
            'to' => ['day' => $this->to_day, 'starts_at' => $this->to_starts_at],
            'outside_teacher_hours' => (bool) $this->outside_teacher_hours,
            'status' => $this->status,
            'approvals' => MoveApprovalResource::collection($this->whenLoaded('approvals')),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'gross_minor' => $this->gross_minor,
        ];
    }
}
