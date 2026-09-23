<?php

namespace App\Domains\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveAssessmentDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        // {learner:public_id} is already resolved to a LearnerProfile by
        // route-model-binding before this runs — not a raw string.
        return $this->user()->can('manage', $this->route('learner'));
    }

    public function rules(): array
    {
        return [
            'grade_level' => ['nullable', 'string', 'max:60'],
            'curriculum' => ['nullable', 'string'],
            'subject_ids' => ['nullable', 'array'],
            'academic_challenges' => ['nullable', 'array'],
            'learning_goals' => ['nullable', 'array'],
            'budget_tier' => ['nullable', 'in:basic,standard,premium'],
            'preferred_format' => ['nullable', 'in:one_on_one,group,no_preference'],
            'session_frequency' => ['nullable', 'in:weekly,twice_weekly,custom'],
            'availability' => ['nullable', 'array'],
        ];
    }
}
