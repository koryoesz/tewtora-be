<?php

namespace App\Domains\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /classes/{id}/enrollments. Which learner the seat is for; who pays is
 * always the signed-in account (never a request field — CLAUDE.md "the payer
 * is always the account owner"). Ownership is checked by TewtoraClassPolicy::enroll.
 */
class EnrollInClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'learner_id' => ['required', 'uuid'],
        ];
    }
}
