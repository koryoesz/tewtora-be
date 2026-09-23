<?php

namespace App\Domains\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveFeedbackDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorized against the session, not a feedback row — on first
        // draft save, no feedback row exists yet to route-bind against.
        return $this->user()->can('manage', $this->route('session'));
    }

    public function rules(): array
    {
        return [
            'attendance' => ['nullable', 'in:present,absent,late'],
            'session_notes' => ['nullable', 'string'],
            'progress_rating' => ['nullable', 'integer', 'between:1,5'],
            'next_steps' => ['nullable', 'string'],
        ];
    }
}
