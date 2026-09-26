<?php

namespace App\Domains\Auth\Http\Requests;

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
            'pin' => ['sometimes', 'digits:4'],
        ];
    }
}
