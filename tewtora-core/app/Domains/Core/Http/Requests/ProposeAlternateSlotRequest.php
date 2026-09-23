<?php

namespace App\Domains\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProposeAlternateSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('respond', $this->route('moveRequest'));
    }

    public function rules(): array
    {
        return [
            'day' => ['required', 'string'],
            'starts_at' => ['required', 'date_format:H:i'],
        ];
    }
}
