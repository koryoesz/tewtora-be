<?php

namespace App\Domains\Core\Http\Requests;

use App\Domains\Core\Models\TewtoraClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /classes — the frontend's All/Active/Upcoming/Completed tabs plus optional filters (codes for subject/curriculum). */
class BrowseClassesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in([TewtoraClass::PHASE_ACTIVE, TewtoraClass::PHASE_UPCOMING, TewtoraClass::PHASE_COMPLETED])],
            'subject' => ['sometimes', 'string', 'max:60'],
            'curriculum' => ['sometimes', 'string', 'max:60'],
            'level' => ['sometimes', 'string', 'max:40'],
        ];
    }
}
