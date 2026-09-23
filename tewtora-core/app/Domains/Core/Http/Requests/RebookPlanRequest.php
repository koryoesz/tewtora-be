<?php

namespace App\Domains\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RebookPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('plan'));
    }

    public function rules(): array
    {
        return [
            'session_count' => ['required', 'integer', 'min:1'],
            'note_to_teacher' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
