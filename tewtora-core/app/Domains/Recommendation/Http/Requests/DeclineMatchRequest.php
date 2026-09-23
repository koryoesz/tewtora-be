<?php

namespace App\Domains\Recommendation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeclineMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('respond', $this->route('match'));
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
