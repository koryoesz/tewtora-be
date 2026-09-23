<?php

namespace App\Domains\Core\Http\Requests;

use App\Domains\Core\Models\LearnerAccountLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * docs/api-contract.md §3 has the body as `{ learnerId, slotId }`, with
 * `slotId` referencing one of the teacher's computed freeTrialSlots.
 * Simplified here to slot_starts_at/duration_minutes directly, since
 * neither the free-trial-slot computation (against teacher_availability)
 * nor a slot-id lookup table exists yet in this pass — flagged, not
 * silently worked around.
 */
class CreateTrialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'learner_profile_id' => [
                'required',
                'integer',
                // Model class, not ->getTable() — see SwitchProfileRequest for why.
                Rule::exists(LearnerAccountLink::class, 'learner_profile_id')
                    ->where('owner_account_id', $this->user()->id),
            ],
            'slot_starts_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:120'],
        ];
    }
}
