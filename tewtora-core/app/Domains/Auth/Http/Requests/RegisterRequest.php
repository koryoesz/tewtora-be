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
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
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
}
