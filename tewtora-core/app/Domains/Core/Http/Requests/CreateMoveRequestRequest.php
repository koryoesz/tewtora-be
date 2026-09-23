<?php

namespace App\Domains\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateMoveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('plan'));
    }

    public function rules(): array
    {
        return [
            'route' => ['required', 'in:move_learner,move_group,to_one_to_one'],
            'to_day' => ['required', 'string'],
            'to_starts_at' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
