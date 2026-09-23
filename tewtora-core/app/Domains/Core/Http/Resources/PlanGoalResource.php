<?php

namespace App\Domains\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanGoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'label' => $this->label,
            'pct' => (float) $this->pct,
        ];
    }
}
