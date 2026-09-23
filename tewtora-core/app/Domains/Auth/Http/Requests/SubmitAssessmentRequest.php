<?php

namespace App\Domains\Auth\Http\Requests;

use App\Domains\Auth\Http\Rules\ConsentRequiredIfMinor;
use Illuminate\Foundation\Http\FormRequest;

class SubmitAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // {learner:public_id} is already resolved to a LearnerProfile by
        // route-model-binding before this runs — not a raw string.
        return $this->user()->can('manage', $this->route('learner'));
    }

    public function rules(): array
    {
        // Deliberately no 'sometimes' — that would skip validation
        // entirely (including the consent rule) when the field is omitted
        // from the request, which is exactly the case a minor's missing
        // consent needs to be caught.
        return [
            'consent_given' => ['nullable', 'boolean', new ConsentRequiredIfMinor($this->route('learner'))],
        ];
    }
}
