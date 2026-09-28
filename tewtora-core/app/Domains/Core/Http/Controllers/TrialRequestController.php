<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Core\Http\Requests\CreateTrialRequestRequest;
use App\Domains\Core\Http\Requests\RespondTrialRequestRequest;
use App\Domains\Core\Http\Resources\TrialRequestResource;
use App\Domains\Core\Models\TrialRequest;
use App\Domains\Core\Repositories\TrialRequestRepositoryInterface;
use App\Domains\Core\Services\TrialRequestService;
use Illuminate\Http\Request;

class TrialRequestController
{
    public function __construct(
        private readonly TrialRequestRepositoryInterface $trialRequests,
        private readonly TrialRequestService $service,
    ) {}

    /** docs/api-contract.md §3: POST /teachers/:id/trial-requests */
    public function store(CreateTrialRequestRequest $request, int $teacherId): TrialRequestResource
    {
        // public_id is DB-generated (DEFAULT (UUID())) — create()'s
        // in-memory model doesn't know it without a refresh.
        $trialRequest = $this->trialRequests->create([
            'learner_profile_id' => $request->validated('learner_profile_id'),
            'teacher_id' => $teacherId,
            'slot_starts_at' => $request->validated('slot_starts_at'),
            'duration_minutes' => $request->validated('duration_minutes'),
            'expires_at' => now()->addHours(12),
        ])->refresh();

        return new TrialRequestResource($trialRequest);
    }

    /** DELETE /trial-requests/:id — requester cancels a pending request. */
    public function destroy(Request $request, TrialRequest $trialRequest): TrialRequestResource
    {
        $request->user()->can('manage', $trialRequest) || abort(403);

        return new TrialRequestResource($this->service->cancel($trialRequest));
    }

    /** POST /trial-requests/:id/respond — teacher accept/decline. */
    public function respond(RespondTrialRequestRequest $request, TrialRequest $trialRequest): TrialRequestResource
    {
        $trialRequest = match ($request->validated('decision')) {
            'accept' => $this->service->accept($trialRequest),
            'decline' => $this->service->decline($trialRequest),
        };

        return new TrialRequestResource($trialRequest);
    }
}
