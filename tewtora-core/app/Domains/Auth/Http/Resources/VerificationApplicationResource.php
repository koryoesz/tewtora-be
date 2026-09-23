<?php

namespace App\Domains\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VerificationApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'checks' => $this->whenLoaded('verificationChecks', fn () => $this->verificationChecks->map(fn ($check) => [
                'kind' => $check->kind,
                'state' => $check->state,
                'evidence' => $check->evidence,
                'checked_at' => $check->checked_at?->toIso8601String(),
            ])),
            'status' => $this->verification_status,
        ];
    }
}
