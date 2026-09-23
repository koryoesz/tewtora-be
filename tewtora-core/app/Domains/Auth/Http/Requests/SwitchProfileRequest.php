<?php

namespace App\Domains\Auth\Http\Requests;

use App\Domains\Auth\Models\LearnerProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SwitchProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'learner_id' => [
                'required',
                'string',
                // Pass the model class, not (new LearnerProfile)->getTable()
                // — Rule::exists() treats a dotted table string as
                // "{connection}.{table}" (DatabaseRule::resolveTableName()),
                // so 'auth.learner_profiles' was read as connection
                // "auth" (not configured) rather than the schema-qualified
                // table on the default connection. The model-class form
                // special-cases an already-dotted getTable() and leaves it
                // alone.
                Rule::exists(LearnerProfile::class, 'public_id'),
            ],
        ];
    }
}
