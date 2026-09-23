<?php

namespace App\Domains\Auth\Http\Requests;

use App\Domains\Auth\Services\AccountSearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActAsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(AccountSearchService::allowedActAsReasons())],
        ];
    }
}
