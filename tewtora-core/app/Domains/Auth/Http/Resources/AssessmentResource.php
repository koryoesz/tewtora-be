<?php

namespace App\Domains\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status,
            'academic_challenges' => $this->academic_challenges,
            'learning_goals' => $this->learning_goals,
            'budget_tier' => $this->budget_tier,
            'preferred_format' => $this->preferred_format,
            'session_frequency' => $this->session_frequency,
            'availability' => $this->availability,
            'consent_given' => (bool) $this->consent_given,
            'submitted_at' => $this->status === 'submitted' ? $this->submitted_at?->toIso8601String() : null,
        ];
    }
}
