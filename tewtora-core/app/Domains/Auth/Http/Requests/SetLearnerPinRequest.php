<?php

namespace App\Domains\Auth\Http\Requests;

use App\Domains\Auth\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A dedicated write path (not PATCH /learners/{id}) per the frontend's own
 * request, so a PIN reset has its own auth check and can't be silently
 * included/excluded by `sometimes` validation on the general-purpose PATCH.
 * Only the owning parent may set/reset a child's PIN — never the child's
 * own linked login, which is exactly the 'manage' vs 'view' split
 * LearnerProfilePolicy already draws for every other mutation.
 */
class SetLearnerPinRequest extends FormRequest
{
    /** Weak enough that a second person guessing the child's birth year etc. would try these first. */
    private const BLOCKED_PINS = [
        '0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999',
        '1234', '2345', '3456', '4567', '5678', '6789', '7890', '0123',
        '4321', '9876', '8765', '7654', '6543', '5432', '3210',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('learner'));
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'digits:4'],
            // Only required by the controller when this profile has no
            // linked login yet (first-time setup) — optional here since a
            // reset on an already-linked profile doesn't need one.
            'username' => [
                'sometimes', 'string', 'min:3', 'max:30', 'alpha_dash',
                // Model class, not ->getTable() — see SwitchProfileRequest for why.
                Rule::unique(Account::class, 'username')->withoutTrashed(),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (in_array($this->input('pin'), self::BLOCKED_PINS, true)) {
                $validator->errors()->add('pin', 'Choose a less predictable 4-digit PIN.');
            }
        });
    }
}
