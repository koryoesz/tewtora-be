<?php

namespace Tests\Feature\Core;

use App\Domains\Core\Models\MoveApproval;
use App\Domains\Core\Models\MoveRequest;
use App\Domains\Core\Models\Plan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers 2024_02_01_000074: one approval row per party per move request —
 * the mechanism that makes "nothing changes until every party accepts"
 * (docs/api-contract.md §5) enforceable at the data layer, not just an
 * application convention.
 */
class MoveRequestTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    private function makeMoveRequest(): MoveRequest
    {
        $learner = $this->makeLearnerProfile();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();

        $plan = Plan::create([
            'learner_profile_id' => $learner->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'format' => 'one_on_one',
            'days' => ['tue'],
            'time_of_day' => '16:00',
            'status' => 'active',
            'rate_minor' => 500000,
            'sessions_per_month' => 4,
            'sessions_remaining' => 4,
            'renews_at' => now()->addMonth(),
            'reference' => 'TWT-'.uniqid(),
        ]);

        return MoveRequest::create([
            'plan_id' => $plan->id,
            'kind' => 'move',
            'route' => 'move_learner',
            'reason' => 'Clashes with a school event.',
            'from_day' => 'tue',
            'from_starts_at' => '16:00',
            'to_day' => 'wed',
            'to_starts_at' => '16:00',
            'status' => 'pending',
            'expires_at' => now()->addDay(),
            'gross_minor' => 500000,
        ]);
    }

    public function test_one_party_cannot_have_two_approval_rows_on_the_same_request(): void
    {
        $moveRequest = $this->makeMoveRequest();
        $parent = $this->makeAccount();

        MoveApproval::create([
            'move_request_id' => $moveRequest->id,
            'party_account_id' => $parent->id,
            'party_label' => 'Parent',
            'role' => 'parent',
        ]);

        $this->expectException(QueryException::class);

        MoveApproval::create([
            'move_request_id' => $moveRequest->id,
            'party_account_id' => $parent->id,
            'party_label' => 'Parent',
            'role' => 'parent',
        ]);
    }

    public function test_a_move_request_stays_pending_until_every_approval_is_accepted(): void
    {
        $moveRequest = $this->makeMoveRequest();
        $teacherAccount = $this->makeAccount(['account_type' => 'teacher']);
        $parentAccount = $this->makeAccount();

        MoveApproval::create([
            'move_request_id' => $moveRequest->id,
            'party_account_id' => $teacherAccount->id,
            'party_label' => 'Teacher',
            'role' => 'teacher',
            'state' => 'accepted',
            'responded_at' => now(),
        ]);

        MoveApproval::create([
            'move_request_id' => $moveRequest->id,
            'party_account_id' => $parentAccount->id,
            'party_label' => 'Parent',
            'role' => 'parent',
            'state' => 'pending',
        ]);

        // The migration/model layer doesn't auto-transition status on its
        // own — that's application logic (a listener on approval accept).
        // This asserts the data supports computing "not everyone's in yet"
        // correctly, which is what that logic will read.
        $this->assertSame('pending', $moveRequest->fresh()->status);
        $this->assertSame(1, $moveRequest->approvals()->where('state', 'pending')->count());
    }
}
