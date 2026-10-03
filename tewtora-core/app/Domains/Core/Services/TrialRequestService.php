<?php

namespace App\Domains\Core\Services;

use App\Domains\Core\Exceptions\TrialRequestExpiredException;
use App\Domains\Core\Exceptions\TrialRequestNotPendingException;
use App\Domains\Core\Models\TrialRequest;
use App\Domains\Core\Repositories\SessionRepositoryInterface;
use App\Domains\Core\Repositories\TrialRequestRepositoryInterface;

class TrialRequestService
{
    public function __construct(
        private readonly TrialRequestRepositoryInterface $trialRequests,
        private readonly SessionRepositoryInterface $sessions,
    ) {}

    /**
     * docs/api-contract.md §3: accepting a trial request's side effect is
     * "create a session" — never a payment intent, the trial is free.
     * Works from 'pending' (accepting the original slot) or 'countered'
     * (the family accepting the teacher's alternate time instead) — the
     * session is scheduled against whichever slot is actually current for
     * the request's state, never the original slot_starts_at once a
     * counter has been made.
     */
    public function accept(TrialRequest $trialRequest): TrialRequest
    {
        $this->assertRespondable($trialRequest);

        $startsAt = $trialRequest->status === 'countered'
            ? $trialRequest->countered_starts_at
            : $trialRequest->slot_starts_at;

        $session = $this->sessions->create([
            'learner_profile_id' => $trialRequest->learner_profile_id,
            'teacher_id' => $trialRequest->teacher_id,
            'scheduled_at' => $startsAt,
            'format' => 'one_on_one',
            'is_trial' => true,
            'status' => 'confirmed',
        ]);

        return $this->trialRequests->markAccepted($trialRequest, $session->id);
    }

    /**
     * From 'pending' (the teacher declining outright) or 'countered' (the
     * family declining the teacher's alternate time). `$reason` is only
     * meaningful for the teacher's own decline — RespondTrialRequestRequest
     * doesn't accept it at all when the family is the one responding to a
     * countered slot.
     */
    public function decline(TrialRequest $trialRequest, ?string $reason = null): TrialRequest
    {
        $this->assertRespondable($trialRequest);

        return $this->trialRequests->markDeclined($trialRequest, $reason);
    }

    /**
     * docs/needed-endpoints-trial-requests.md: the teacher offering an
     * alternate time instead of accepting/declining the original slot
     * outright. Only ever from 'pending' — once already countered, the
     * family is the one who responds next (accept/decline), not a second
     * counter.
     */
    public function counter(TrialRequest $trialRequest, string $altStartsAt): TrialRequest
    {
        $this->assertPending($trialRequest);

        return $this->trialRequests->markCountered($trialRequest, $altStartsAt);
    }

    public function cancel(TrialRequest $trialRequest): TrialRequest
    {
        $this->assertCancellable($trialRequest);

        return $this->trialRequests->markCancelled($trialRequest);
    }

    private function assertPending(TrialRequest $trialRequest): void
    {
        if ($trialRequest->status !== 'pending') {
            throw new TrialRequestNotPendingException($trialRequest->id, $trialRequest->status);
        }

        if ($trialRequest->expires_at->isPast()) {
            throw new TrialRequestExpiredException($trialRequest->id);
        }
    }

    /** Pending (teacher responding to the original ask) or countered (family responding to the alternate). */
    private function assertRespondable(TrialRequest $trialRequest): void
    {
        if (! in_array($trialRequest->status, ['pending', 'countered'], true)) {
            throw new TrialRequestNotPendingException($trialRequest->id, $trialRequest->status);
        }

        if ($trialRequest->expires_at->isPast()) {
            throw new TrialRequestExpiredException($trialRequest->id);
        }
    }

    /**
     * Withdraw must work from 'countered' too (a family withdrawing after a
     * counter-offer, not just while still 'pending') —
     * docs/needed-endpoints-trial-requests.md §4. No expiry check here
     * (unlike assertPending/assertRespondable): withdrawing your own
     * request should work regardless of whether its 12-hour reply window
     * has lapsed.
     */
    private function assertCancellable(TrialRequest $trialRequest): void
    {
        if (! in_array($trialRequest->status, ['pending', 'countered'], true)) {
            throw new TrialRequestNotPendingException($trialRequest->id, $trialRequest->status);
        }
    }
}
