<?php

namespace App\Domains\Auth\Http\Requests;

use App\Domains\Auth\Models\Curriculum;
use App\Domains\Auth\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /teachers — docs/needed-endpoints-browse-matching.md §1's "minimum
 * useful version" of /matches. Every filter is optional; an empty query
 * returns every verified teacher.
 */
class BrowseTeachersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // any authenticated account — same posture as GET /teachers/{id}.
    }

    public function rules(): array
    {
        return [
            'subject' => ['sometimes', Rule::exists(Subject::class, 'code')],
            'curriculum' => ['sometimes', Rule::exists(Curriculum::class, 'code')],
            'level' => ['sometimes', 'in:'.implode(',', UpdateTeacherProfileRequest::LEVELS)],
            'format' => ['sometimes', 'in:one_on_one,group'],
        ];
    }
}
