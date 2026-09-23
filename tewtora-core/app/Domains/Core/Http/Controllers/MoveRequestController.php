<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Core\Http\Requests\CreateMoveRequestRequest;
use App\Domains\Core\Http\Requests\ProposeAlternateSlotRequest;
use App\Domains\Core\Http\Resources\MoveRequestResource;
use App\Domains\Core\Models\MoveRequest;
use App\Domains\Core\Models\Plan;
use App\Domains\Core\Models\TeacherAccountLink;
use App\Domains\Core\Repositories\MoveRequestRepositoryInterface;
use App\Domains\Core\Services\MoveRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * docs/api-contract.md §5's `GET /plans/:id/candidate-slots` (server-computed
 * verdict per slot) is NOT implemented — it needs teacher_availability data,
 * which lives in Auth and has no Core-side read-model mirror yet (unlike
 * learner/teacher account links). Flagged rather than faked.
 */
class MoveRequestController
{
    public function __construct(
        private readonly MoveRequestRepositoryInterface $moveRequests,
        private readonly MoveRequestService $service,
    ) {}

    public function store(CreateMoveRequestRequest $request, Plan $plan): MoveRequestResource
    {
        $teacherAccountId = TeacherAccountLink::where('teacher_id', $plan->teacher_id)->value('account_id');

        $moveRequest = $this->service->create($plan, $teacherAccountId, [
            ...$request->validated(),
            'from_day' => $plan->days[0] ?? $request->validated('to_day'),
            'from_starts_at' => $plan->time_of_day,
        ]);

        return new MoveRequestResource($moveRequest->load('approvals'));
    }

    public function show(Request $request, MoveRequest $moveRequest): MoveRequestResource
    {
        $request->user()->can('view', $moveRequest) || abort(403);

        return new MoveRequestResource($moveRequest->load('approvals'));
    }

    /** GET /move-requests?teacherId= — scoped to the authenticated teacher regardless of the query param, per policy. */
    public function forTeacher(Request $request): AnonymousResourceCollection
    {
        $teacherId = TeacherAccountLink::where('account_id', $request->user()->id)->value('teacher_id');
        $teacherId ?? abort(403);

        $moveRequests = $this->moveRequests->forTeacher($teacherId);
        $moveRequests->load('approvals');

        return MoveRequestResource::collection($moveRequests);
    }

    public function accept(Request $request, MoveRequest $moveRequest): MoveRequestResource
    {
        $request->user()->can('respond', $moveRequest) || abort(403);

        return new MoveRequestResource($this->service->accept($moveRequest, $request->user()->id)->load('approvals'));
    }

    public function decline(Request $request, MoveRequest $moveRequest): MoveRequestResource
    {
        $request->user()->can('respond', $moveRequest) || abort(403);

        return new MoveRequestResource($this->service->decline($moveRequest, $request->user()->id)->load('approvals'));
    }

    public function proposeAlternate(ProposeAlternateSlotRequest $request, MoveRequest $moveRequest): MoveRequestResource
    {
        return new MoveRequestResource(
            $this->service->proposeAlternate($moveRequest, $request->validated('day'), $request->validated('starts_at'))
        );
    }

    public function withdraw(Request $request, MoveRequest $moveRequest): MoveRequestResource
    {
        $request->user()->can('manage', $moveRequest) || abort(403);

        return new MoveRequestResource($this->service->withdraw($moveRequest));
    }
}
