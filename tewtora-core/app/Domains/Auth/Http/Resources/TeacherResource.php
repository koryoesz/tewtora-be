<?php

namespace App\Domains\Auth\Http\Resources;

use App\Domains\Auth\Support\Weekday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            // Honestly null, not a fabricated placeholder, when a teacher
            // hasn't set one yet — see 2024_02_01_000107's docblock.
            'name' => $this->full_name,
            'years_teaching' => $this->years_experience,
            'about' => $this->bio,
            'format' => $this->preferred_format,
            'levels' => $this->levels ?? [],
            'group_size' => $this->max_group_size,
            'price_per_session_minor' => $this->rate_minor,
            'currency_code' => $this->currency_code,
            'rating_avg' => $this->rating_avg,
            'verification' => $this->whenLoaded('verificationChecks', fn () => $this->verificationChecks->mapWithKeys(
                fn ($check) => [$check->kind => $check->state]
            )),
            'subjects' => $this->whenLoaded('subjects', fn () => $this->subjects->pluck('code')),
            'curricula' => $this->whenLoaded('curricula', fn () => $this->curricula->pluck('code')),
            // Same {day, starts_at, ends_at} shape it's written in
            // (UpdateTeacherProfileRequest) — translated back out of
            // teacher_availability's day_of_week/start_time/end_time.
            'availability' => $this->whenLoaded('availability', fn () => $this->availability->map(fn ($slot) => [
                'day' => Weekday::toCode($slot->day_of_week),
                'starts_at' => substr($slot->start_time, 0, 5),
                'ends_at' => substr($slot->end_time, 0, 5),
            ])),
        ];
    }
}
