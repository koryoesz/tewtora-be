<?php

namespace App\Domains\Auth\Http\Requests;

use App\Domains\Auth\Models\Curriculum;
use App\Domains\Auth\Models\Subject;
use App\Shared\Support\ContactInfoFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /teachers/:id — a teacher editing their own profile (the onboarding
 * wizard's step 1-3 + bio: docs/needed-endpoints-teacher-onboarding.md §3).
 * Deliberately additive to GET /teachers/:id's existing shape rather than a
 * separate draft object: unlike AssessmentResource, there's no analogous
 * "submit" moment here — a teacher can keep editing subjects/rate/
 * availability/bio indefinitely, verified or not, and none of these fields
 * overlap with TeacherVerificationCheck's own kinds (government-id/
 * credentials/teaching-demo) — so this intentionally never reads or writes
 * verification_status. Editing a profile field isn't "re-applying," and
 * doesn't need to re-trigger or bypass admin review.
 */
class UpdateTeacherProfileRequest extends FormRequest
{
    /** Fixed, closed vocabulary — see 2024_02_01_000105's docblock for why this isn't a lookup table. */
    public const LEVELS = [
        'primary_1_3',
        'primary_4_6',
        'jss_1_3',
        'sss_1_3',
        'year_7_9',
        'year_10_11',
        'ib_a_level',
    ];

    public function authorize(): bool
    {
        // {teacher:public_id} is already resolved to a Teacher by route
        // model binding before this runs — not a raw string.
        return $this->user()->can('manage', $this->route('teacher'));
    }

    public function rules(): array
    {
        return [
            // A browse list (docs/needed-endpoints-browse-matching.md §1) is
            // unusable with every card anonymous — same contact-info reject
            // as `about` below, since this is just as publicly displayed.
            'name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:160', function ($attribute, $value, $fail) {
                if ($value !== null && ContactInfoFilter::containsContactInfo($value)) {
                    $fail('The name must not contain phone numbers, emails, or links.');
                }
            }],
            'subjects' => ['sometimes', 'array'],
            'subjects.*' => [Rule::exists(Subject::class, 'code')],
            'curricula' => ['sometimes', 'array'],
            'curricula.*' => [Rule::exists(Curriculum::class, 'code')],
            'levels' => ['sometimes', 'array'],
            'levels.*' => ['in:'.implode(',', self::LEVELS)],
            'format' => ['sometimes', 'in:one_on_one,group,both'],
            'price_per_session_minor' => ['sometimes', 'integer', 'min:0'],
            'years_teaching' => ['sometimes', 'integer', 'min:0'],
            // Same {day, starts_at, ends_at} shape as auth.assessments.
            // availability (SaveAssessmentDraftRequest) — reused rather than
            // inventing a second one, per
            // docs/needed-endpoints-teacher-onboarding.md §3.
            'availability' => ['sometimes', 'array'],
            'availability.*.day' => ['required_with:availability', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'availability.*.starts_at' => ['required_with:availability', 'date_format:H:i'],
            'availability.*.ends_at' => ['required_with:availability', 'date_format:H:i', 'after:availability.*.starts_at'],
            // 40-char floor and the contact-info reject are both from the
            // onboarding spec directly ("Reject a bio with phone numbers,
            // emails or links... or under 40 characters") — unlike a
            // message body (MessageRedactor), a bio is authored once and
            // reviewed, so it's rejected outright rather than silently
            // stripped.
            'about' => ['sometimes', 'nullable', 'string', 'min:40', 'max:2000', function ($attribute, $value, $fail) {
                if ($value !== null && ContactInfoFilter::containsContactInfo($value)) {
                    $fail('The bio must not contain phone numbers, emails, or links.');
                }
            }],
        ];
    }
}
