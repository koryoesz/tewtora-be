<?php

namespace App\Domains\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /classes/sessions?from=&to= — Lagos calendar dates, inclusive, at most 62 days (the week strip asks for 7). */
class ClassScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', function ($attribute, $value, $fail) {
                if (strtotime($value) - strtotime((string) $this->input('from')) > 61 * 86400) {
                    $fail('The date range can be at most 62 days.');
                }
            }],
        ];
    }
}
