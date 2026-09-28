<?php

namespace App\Domains\Auth\Http\Requests;

use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\Curriculum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateLearnerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // §1: only a parent adds a child. An independent student's own
        // profile is created at onboarding, not through this endpoint.
        return $this->user()->account_type === 'parent';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'grade_level' => ['required', 'string', 'max:60'],
            // Model class, not ->getTable() — see SwitchProfileRequest for why.
            'curriculum' => ['required', Rule::exists(Curriculum::class, 'code')],
            // Optional at creation — SetLearnerPinRequest's blocklist doesn't
            // apply here since it needs its own FormRequest to run
            // withValidator; a weak PIN set at creation just isn't blocked.
            // Acceptable since the same reset endpoint (which does block it)
            // is always available immediately after.
            //
            // username + pin together create the child's own login account
            // right away (email is deliberately not required for it — a
            // child signs in with username + PIN, never email/password).
            // Either alone is meaningless, so each requires the other.
            'username' => [
                'sometimes', 'required_with:pin', 'string', 'min:3', 'max:30', 'alpha_dash',
                // Model class, not ->getTable() — see SwitchProfileRequest for why.
                Rule::unique(Account::class, 'username')->withoutTrashed(),
            ],
            'pin' => ['sometimes', 'required_with:username', 'digits:4'],
        ];
    }
}
