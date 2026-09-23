<?php

namespace App\Domains\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SafeguardingIncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'reported_at' => $this->reported_at?->toIso8601String(),
            'severity' => $this->severity,
            'status' => $this->status,
            'summary' => $this->summary,
            'closed_note' => $this->closed_note,
        ];
    }
}
