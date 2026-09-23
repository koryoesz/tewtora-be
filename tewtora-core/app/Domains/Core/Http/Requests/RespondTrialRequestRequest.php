<?php

namespace App\Domains\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RespondTrialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('respond', $this->route('trialRequest'));
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:accept,decline'],
        ];
    }
}
