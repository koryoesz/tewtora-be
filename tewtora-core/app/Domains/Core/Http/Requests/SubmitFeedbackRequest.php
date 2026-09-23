<?php

namespace App\Domains\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * docs/api-contract.md §11: "Reject an empty note — this is the record
 * the parent gets of what happened." Mirrors core.feedback's
 * chk_feedback_notes_present_if_submitted / _rating_present_if_submitted
 * CHECKs at the application layer, so the rejection is a clean 422, not a
 * raw SQL error.
 */
class SubmitFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('session'));
    }

    public function rules(): array
    {
        return [
            'attendance' => ['nullable', 'in:present,absent,late'],
            'session_notes' => ['required', 'string', 'min:1'],
            'progress_rating' => ['required', 'integer', 'between:1,5'],
            'next_steps' => ['nullable', 'string'],
        ];
    }
}
