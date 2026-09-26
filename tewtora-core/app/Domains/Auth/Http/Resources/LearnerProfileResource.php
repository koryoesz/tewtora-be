<?php

namespace App\Domains\Auth\Http\Resources;

use App\Domains\Auth\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearnerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->full_name,
            'initials' => collect(explode(' ', $this->full_name))->map(fn ($p) => strtoupper($p[0] ?? ''))->implode(''),
            'grade_label' => $this->grade_level,
            'has_pin' => $this->hasPin(),
            'archived' => $this->trashed(),
            'curriculum' => $this->whenLoaded('curriculum', fn () => $this->curriculum->code),
            'assessment_complete' => Assessment::where('learner_profile_id', $this->id)
                ->where('status', 'submitted')
                ->exists(),
        ];
    }
}
