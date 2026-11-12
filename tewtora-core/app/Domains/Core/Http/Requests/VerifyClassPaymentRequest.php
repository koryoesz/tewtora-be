<?php

namespace App\Domains\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /class-enrollments/verify-payment — the `reference` (or `trxref`)
 * Paystack appended to the return URL. Who may verify is checked against
 * the enrolment it resolves to (ClassEnrollmentPolicy::pay).
 */
class VerifyClassPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('reference') && $this->filled('trxref')) {
            $this->merge(['reference' => $this->input('trxref')]);
        }
    }

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._=-]+$/'],
        ];
    }
}
