<?php

namespace App\Domains\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MoveApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'party_label' => $this->party_label,
            'role' => $this->role,
            'state' => $this->state,
            'responded_at' => $this->responded_at?->toIso8601String(),
        ];
    }
}
