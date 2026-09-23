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
     */
    public function accept(TrialRequest $trialRequest): TrialRequest
    {
        $this->assertPending($trialRequest);

        $session = $this->sessions->create([
            'learner_profile_id' => $trialRequest->learner_profile_id,
            'teacher_id' => $trialRequest->teacher_id,
            'scheduled_at' => $trialRequest->slot_starts_at,
            'format' => 'one_on_one',
            'is_trial' => true,
            'status' => 'confirmed',
        ]);

        return $this->trialRequests->markAccepted($trialRequest, $session->id);
    }

    public function decline(TrialRequest $trialRequest): TrialRequest
    {
        $this->assertPending($trialRequest);

        return $this->trialRequests->markDeclined($trialRequest);
    }

    public function cancel(TrialRequest $trialRequest): TrialRequest
    {
        $this->assertPending($trialRequest);

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
}
