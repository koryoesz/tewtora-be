<?php

namespace App\Domains\Core\Http\Requests;

use App\Domains\Core\Support\TrialDeclineReason;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Who may call this, and which decisions are valid, both depend on the
 * trial request's *current* status — see
 * docs/needed-endpoints-trial-requests.md:
 *   - 'pending': only the teacher may respond, with accept/decline/counter
 *     against the original slot_starts_at.
 *   - 'countered': only the family (requester) may respond, with
 *     accept/decline against countered_starts_at — never 'counter' again.
 * {trialRequest:public_id} is already resolved by route model binding
 * before authorize()/rules() run (same as every other FormRequest in this
 * codebase that reads $this->route(...) directly).
 */
class RespondTrialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $trialRequest = $this->route('trialRequest');

        if ($trialRequest->status === 'countered') {
            return $this->user()->can('manage', $trialRequest);
        }

        return $this->user()->can('respond', $trialRequest);
    }

    public function rules(): array
    {
        $trialRequest = $this->route('trialRequest');
        $isCounterResponse = $trialRequest?->status === 'countered';

        return [
            'decision' => ['required', $isCounterResponse ? 'in:accept,decline' : 'in:accept,decline,counter'],
            // Optional, not required_if — docs/api-contract.md §3's existing
            // `{"decision":"decline"}` with no reason at all must keep
            // working exactly as before (TrialRequestHttpTest already
            // covers this). Only meaningful for the teacher's own decline of
            // the original ask — a family declining a countered time has
            // nothing to give a reason about (that's the teacher's content,
            // not theirs), so it's rejected outright there rather than
            // silently accepted and ignored.
            'reason' => $isCounterResponse
                ? ['prohibited']
                : ['nullable', 'in:'.implode(',', TrialDeclineReason::CODES)],
            'alt_starts_at' => ['required_if:decision,counter', 'date', 'after:now'],
        ];
    }
}
