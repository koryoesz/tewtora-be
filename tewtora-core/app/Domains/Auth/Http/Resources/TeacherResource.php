<?php

namespace App\Domains\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'years_teaching' => $this->years_experience,
            'about' => $this->bio,
            'format' => $this->preferred_format,
            'group_size' => $this->max_group_size,
            'price_per_session_minor' => $this->rate_minor,
            'currency_code' => $this->currency_code,
            'rating_avg' => $this->rating_avg,
            'verification' => $this->whenLoaded('verificationChecks', fn () => $this->verificationChecks->mapWithKeys(
                fn ($check) => [$check->kind => $check->state]
            )),
            'subjects' => $this->whenLoaded('subjects', fn () => $this->subjects->pluck('code')),
            'curricula' => $this->whenLoaded('curricula', fn () => $this->curricula->pluck('code')),
        ];
    }
}
