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
            // Formalized per the frontend's redesigned budget/schedule step:
            // one entry per selected weekday, a real HH:mm free-time window
            // (the matched teacher picks the actual class time within it) —
            // replaces the old {day,band,state} shape, which is why this
            // wasn't validated at all before now (AssessmentResource still
            // types the column as unknown[] at rest; this only constrains
            // what's accepted on write).
            'availability' => ['nullable', 'array'],
            'availability.*.day' => ['required_with:availability', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'availability.*.starts_at' => ['required_with:availability', 'date_format:H:i'],
            'availability.*.ends_at' => ['required_with:availability', 'date_format:H:i', 'after:availability.*.starts_at'],
        ];
    }
}
