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
        // Submit takes the same fields as the draft save (the contract's
        // "same fields as above, all now conceptually final") — without
        // these, $request->validated() strips everything but
        // consent_given, so AssessmentController::submit() would write a
        // row missing whatever was actually sent, even for a brand new
        // assessment never saved as a draft first. Reuses
        // SaveAssessmentDraftRequest's rules as the single source of truth
        // for that shared field set rather than duplicating it — all still
        // nullable, since none of them (learning_goals included — see
        // 2024_02_01_000098) are required to submit.
        //
        // consent_given itself deliberately has no 'sometimes' — that
        // would skip validation entirely (including the consent rule) when
        // the field is omitted from the request, which is exactly the
        // case a minor's missing consent needs to be caught.
        return [
            ...(new SaveAssessmentDraftRequest)->rules(),
            'consent_given' => ['nullable', 'boolean', new ConsentRequiredIfMinor($this->route('learner'))],
        ];
    }
}
