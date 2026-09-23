<?php

namespace App\Domains\Auth\Http\Requests;

use App\Domains\Auth\Models\Curriculum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLearnerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('learner'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'grade_level' => ['sometimes', 'string', 'max:60'],
            // Model class, not ->getTable() — see SwitchProfileRequest for why.
            'curriculum' => ['sometimes', Rule::exists(Curriculum::class, 'code')],
        ];
    }
}
