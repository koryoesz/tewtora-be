<?php

namespace App\Domains\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Two credential shapes, same endpoint: email+password for everyone
     * else, username+pin for a child (who has no email/password — see
     * the 2024_02_01_000096 migration). Exactly one pair, not a mix —
     * `required_without`/`required_with` on both fields of each pair
     * enforces that without a manual after-hook.
     */
    public function rules(): array
    {
        return [
            'email' => ['required_without:username', 'prohibits:username', 'nullable', 'email'],
            'password' => ['required_with:email', 'nullable', 'string'],
            'username' => ['required_without:email', 'nullable', 'string'],
            'pin' => ['required_with:username', 'nullable', 'digits:4'],
        ];
    }
}
