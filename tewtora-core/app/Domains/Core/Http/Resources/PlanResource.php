<?php

namespace App\Domains\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'format' => $this->format,
            'days' => $this->days,
            'time_of_day' => $this->time_of_day,
            'status' => $this->status,
            'rate_minor' => $this->rate_minor,
            'currency_code' => $this->currency_code,
            'sessions_per_month' => $this->sessions_per_month,
            'sessions_remaining' => $this->sessions_remaining,
            'renews_at' => $this->renews_at?->toIso8601String(),
            'reference' => $this->reference,
            'paid_to_date_minor' => $this->paid_to_date_minor,
        ];
    }
}
