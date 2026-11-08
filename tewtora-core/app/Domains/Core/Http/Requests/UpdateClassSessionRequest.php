<?php

namespace App\Domains\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /internal/classes/{id}/sessions/{day}: post or change one day's
 * meeting link (null clears it, so the class-wide link applies), and/or move
 * the day (both times together; days are renumbered by date afterwards).
 */
class UpdateClassSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'meeting_link' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'starts_at' => ['required_with:ends_at', 'date', 'after:now'],
            'ends_at' => ['required_with:starts_at', 'date', 'after:starts_at'],
        ];
    }

    public function messages(): array
    {
        return ['meeting_link.url' => 'The meeting link must be a full https:// link.'];
    }
}
