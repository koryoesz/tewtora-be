<?php

namespace App\Domains\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('participate', $this->route('thread'));
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:4000'],
        ];
    }
}
