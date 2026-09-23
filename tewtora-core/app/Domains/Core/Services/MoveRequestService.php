<?php

namespace App\Domains\Core\Services;

use App\Domains\Core\Exceptions\MoveRequestNotPendingException;
use App\Domains\Core\Models\MoveApproval;
use App\Domains\Core\Models\MoveRequest;
use App\Domains\Core\Models\Plan;
use App\Domains\Core\Repositories\MoveRequestRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §5: "Nothing changes until every party accepts."
 * Applying an accepted move to the plan's actual schedule (updating
 * plan.days/time_of_day, or the specific session it targets) is left as a
 * TODO here rather than guessed at — the contract doesn't specify whether
 * a move changes one occurrence or the plan's whole recurring pattern, and
 * getting that wrong would silently corrupt a family's schedule. The
 * approval bookkeeping (the actual "nothing changes until everyone's in"
 * guarantee) is fully implemented; only the final schedule-write step is
 * deferred.
 *
 * move-group's "resolve the other affected families and create one
 * MoveApproval per party" is also not implemented — it needs a way to
 * find which other plans share the same teacher/slot, which isn't
 * modeled yet. Only the single-party case (move-learner, to-one-to-one)
 * creates just the teacher's approval row.
 */
class MoveRequestService
{
    public function __construct(
        private readonly MoveRequestRepositoryInterface $moveRequests,
    ) {}

    public function create(Plan $plan, int $teacherAccountId, array $data): MoveRequest
    {
        return DB::transaction(function () use ($plan, $teacherAccountId, $data) {
            $moveRequest = $this->moveRequests->create([
                'plan_id' => $plan->id,
                'kind' => 'move',
                'route' => $data['route'],
                'reason' => $data['reason'],
                'from_day' => $data['from_day'],
                'from_starts_at' => $data['from_starts_at'],
                'to_day' => $data['to_day'],
                'to_starts_at' => $data['to_starts_at'],
                'outside_teacher_hours' => $data['outside_teacher_hours'] ?? false,
                'expires_at' => $data['route'] === 'move_group' ? now()->addDays(5) : now()->addDay(),
                'gross_minor' => $plan->rate_minor,
            ]);

            MoveApproval::create([
                'move_request_id' => $moveRequest->id,
                'party_account_id' => $teacherAccountId,
                'party_label' => 'Teacher',
                'role' => 'teacher',
            ]);

            return $moveRequest;
        });
    }

    public function accept(MoveRequest $moveRequest, int $actorAccountId): MoveRequest
    {
        $this->assertPending($moveRequest);

        $approval = MoveApproval::where('move_request_id', $moveRequest->id)
            ->where('party_account_id', $actorAccountId)
            ->firstOrFail();

        $approval->update(['state' => 'accepted', 'responded_at' => now()]);

        $allAccepted = ! MoveApproval::where('move_request_id', $moveRequest->id)
            ->where('state', '!=', 'accepted')
            ->exists();

        if ($allAccepted) {
            $moveRequest->update(['status' => 'accepted']);
        }

        return $moveRequest->fresh();
    }

    public function decline(MoveRequest $moveRequest, int $actorAccountId): MoveRequest
    {
        $this->assertPending($moveRequest);

        MoveApproval::where('move_request_id', $moveRequest->id)
            ->where('party_account_id', $actorAccountId)
            ->update(['state' => 'declined', 'responded_at' => now()]);

        $moveRequest->update(['status' => 'declined']);

        return $moveRequest->fresh();
    }

    public function proposeAlternate(MoveRequest $moveRequest, string $day, string $startsAt): MoveRequest
    {
        $this->assertPending($moveRequest);

        $moveRequest->update(['to_day' => $day, 'to_starts_at' => $startsAt]);

        return $moveRequest;
    }

    public function withdraw(MoveRequest $moveRequest): MoveRequest
    {
        $this->assertPending($moveRequest);

        $moveRequest->update(['status' => 'withdrawn']);

        return $moveRequest;
    }

    private function assertPending(MoveRequest $moveRequest): void
    {
        if ($moveRequest->status !== 'pending') {
            throw new MoveRequestNotPendingException($moveRequest->id, $moveRequest->status);
        }
    }
}
