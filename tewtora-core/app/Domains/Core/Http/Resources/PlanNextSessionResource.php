<?php

namespace App\Domains\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanNextSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'starts_at' => $this->scheduled_at?->toIso8601String(),
            'is_live' => $this->status === 'in_progress',
        ];
    }
}
