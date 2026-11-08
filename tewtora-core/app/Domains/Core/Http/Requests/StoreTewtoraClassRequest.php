<?php

namespace App\Domains\Core\Http\Requests;

use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Support\ClassScheduleGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /internal/classes. JSON or multipart (so the cover image can come in
 * the same request). Admin-only via the route's `admin` middleware.
 *
 * The class days come one of two ways, never both:
 * - `schedule`: a weekly pattern, the way the frontend describes classes —
 *   { starts_on, days: ISO weekdays, start_time, end_time, total_days },
 *   in Lagos time (config classes.display_timezone);
 * - `sessions`: explicit days [{ starts_at, ends_at, meeting_link? }].
 * Either may be left out for a draft; publishing needs at least one day.
 *
 * price_minor omitted or 0 = free; capacity omitted = no cap; meeting_link
 * is the class-wide default, overridable per day. subject/curriculum are
 * codes and teacher_id a teacher public_id, resolved through Auth's
 * directory in the controller.
 */
class StoreTewtoraClassRequest extends FormRequest
{
    public const CURRENCIES = ['NGN', 'GHS', 'ZAR', 'KES', 'USD'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:160'],
            ...self::detailRules(),
            'image' => ['nullable', ...self::imageRules()],
            'publish' => ['sometimes', 'boolean'],
        ];
    }

    /** Shared with UpdateTewtoraClassRequest (which prefixes each with `sometimes`). */
    public static function detailRules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:5000'],
            'subject' => ['nullable', 'string', 'max:60'],
            'curriculum' => ['nullable', 'string', 'max:60'],
            'level' => ['nullable', 'string', 'max:40'],
            'format' => [Rule::in(TewtoraClass::FORMATS)],
            'art' => ['nullable', Rule::in(TewtoraClass::ARTS)],
            'teacher_id' => ['nullable', 'uuid'],
            'price_minor' => ['integer', 'min:0', 'max:100000000000'],
            'currency_code' => [Rule::in(self::CURRENCIES)],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'meeting_link' => ['nullable', 'url:https', 'max:500'],
            'schedule' => ['prohibits:sessions', 'array'],
            'schedule.starts_on' => ['required_with:schedule', 'date_format:Y-m-d', 'after_or_equal:today'],
            'schedule.days' => ['required_with:schedule', 'array', 'min:1', 'max:7'],
            'schedule.days.*' => ['integer', 'between:1,7', 'distinct'],
            'schedule.start_time' => ['required_with:schedule', 'date_format:H:i'],
            'schedule.end_time' => ['required_with:schedule', 'date_format:H:i', 'after:schedule.start_time'],
            'schedule.total_days' => ['required_with:schedule', 'integer', 'min:1', 'max:200'],
            'sessions' => ['array', 'max:200'],
            'sessions.*.starts_at' => ['required', 'date', 'after:now'],
            'sessions.*.ends_at' => ['required', 'date', 'after:sessions.*.starts_at'],
            'sessions.*.meeting_link' => ['nullable', 'url:https', 'max:500'],
        ];
    }

    public static function imageRules(): array
    {
        return ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=400,min_height=200,max_width=6000,max_height=6000'];
    }

    public function messages(): array
    {
        return self::sharedMessages();
    }

    public static function sharedMessages(): array
    {
        return [
            'meeting_link.url' => 'The meeting link must be a full https:// link.',
            'sessions.*.meeting_link.url' => 'Each meeting link must be a full https:// link.',
            'sessions.*.ends_at.after' => 'Each class day must end after it starts.',
            'sessions.*.starts_at.after' => 'Class days must be in the future.',
            'schedule.end_time.after' => 'The class must end after it starts.',
        ];
    }

    /**
     * The class days this request describes, or null if it doesn't set any.
     *
     * @return list<array{starts_at: string, ends_at: string, meeting_link?: ?string}>|null
     */
    public function classDays(): ?array
    {
        if ($this->has('schedule')) {
            $s = $this->validated('schedule');

            return ClassScheduleGenerator::generate($s['starts_on'], $s['days'], $s['start_time'], $s['end_time'], (int) $s['total_days'], config('classes.display_timezone'));
        }

        return $this->has('sessions') ? $this->validated('sessions', []) : null;
    }

    /** Multipart sends booleans as strings ("true"); normalise before the boolean rule sees them. */
    protected function prepareForValidation(): void
    {
        if ($this->has('publish') && is_string($this->input('publish'))) {
            $this->merge(['publish' => filter_var($this->input('publish'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        }

        if (is_string($this->input('currency_code'))) {
            $this->merge(['currency_code' => strtoupper($this->input('currency_code'))]);
        }
    }
}
