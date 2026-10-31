<?php

namespace App\Domains\Auth\Http\Requests;

use App\Domains\Auth\Models\Account;
use App\Shared\Support\ContactInfoFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Self-service sign-up for everyone except a child (whose login is only
     * ever created by its parent — LearnerProfileController::store/setPin)
     * and admin (staff-only). A teacher signs up with just a name on top of
     * the shared fields; the rest of the profile (subjects, rate, format,
     * experience, bio) comes afterwards via PATCH /teachers/:id.
     */
    public function rules(): array
    {
        return [
            'account_type' => ['required', Rule::in(['parent', 'independent_student', 'teacher'])],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Model class, not ->getTable() — see SwitchProfileRequest for why.
                // withoutTrashed() matches ux_accounts_email_live: a
                // soft-deleted account doesn't block re-registration.
                Rule::unique(Account::class, 'email')->withoutTrashed(),
            ],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            // E.164 shape after prepareForValidation() strips formatting:
            // optional leading +, then 7-15 digits (fits VARCHAR(20)).
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            // Stored on auth.teachers.full_name — accounts has no name column
            // for any role, so for a non-teacher it's excluded from
            // validated() rather than accepted and silently dropped later.
            // Same rules as UpdateTeacherProfileRequest's `name`, since it's
            // the same publicly displayed field.
            'name' => ['exclude_unless:account_type,teacher', 'required', 'string', 'min:2', 'max:160', function ($attribute, $value, $fail) {
                if (ContactInfoFilter::containsContactInfo($value)) {
                    $fail('The name must not contain phone numbers, emails, or links.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number, for example +2348012345678.',
        ];
    }

    /**
     * Accepts a phone sent as a JSON number as well as a string (number
     * inputs on the frontend often serialise that way), and strips the
     * formatting people type: spaces, dashes, dots, brackets. What gets
     * validated, and stored, is just the optional + and the digits.
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_int($phone) || is_string($phone)) {
            $clean = preg_replace('/[\s().\-]/', '', (string) $phone);
            $this->merge(['phone' => $clean === '' ? null : $clean]);
        }
    }
}
