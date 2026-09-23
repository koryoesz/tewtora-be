<?php

namespace App\Domains\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** docs/api-contract.md §14: "Reject an empty note — always." */
class DecideVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the RequireAdmin route middleware
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approved,rejected,needs_more'],
            'note' => ['required', 'string', 'min:1'],
        ];
    }
}
