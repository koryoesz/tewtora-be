<?php

namespace Tests\Feature\Core;

use App\Domains\Core\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/** docs/api-contract.md §5: "Nothing changes until every party accepts." */
class MoveRequestHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    private function makeApprovedPlan(): array
    {
        $parent = $this->makeAccount();
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id]);
        $this->linkLearnerToCore($learner);
        $teacherAccount = $this->makeAccount(['account_type' => 'teacher']);
        $teacher = $this->makeTeacher(['account_id' => $teacherAccount->id]);
        $this->linkTeacherToCore($teacher);
        $subject = $this->makeSubject();

        // public_id is DB-generated (DEFAULT (UUID())) — create()'s
        // in-memory model doesn't know it without a refresh, which every
        // route in these tests needs (they're keyed by public_id).
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
        ])->refresh();

        return [$plan, $parent, $teacherAccount];
    }

    public function test_creating_a_move_request_only_creates_the_teachers_approval(): void
    {
        [$plan, $parent] = $this->makeApprovedPlan();
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/plans/{$plan->public_id}/move-requests", [
                'route' => 'move_learner',
                'to_day' => 'wed',
                'to_starts_at' => '16:00',
                'reason' => 'Clashes with a school trip.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonCount(1, 'data.approvals');
    }

    public function test_the_move_request_only_applies_once_the_teacher_accepts(): void
    {
        [$plan, $parent, $teacherAccount] = $this->makeApprovedPlan();
        $parentToken = $this->tokenFor($parent);

        $create = $this->withHeader('Authorization', "Bearer {$parentToken}")
            ->postJson("/api/v1/plans/{$plan->public_id}/move-requests", [
                'route' => 'move_learner',
                'to_day' => 'wed',
                'to_starts_at' => '16:00',
                'reason' => 'Clashes with a school trip.',
            ]);

        $moveRequestId = $create->json('data.id');
        $teacherToken = $this->tokenFor($teacherAccount);

        $accept = $this->withHeader('Authorization', "Bearer {$teacherToken}")
            ->postJson("/api/v1/move-requests/{$moveRequestId}/accept");

        $accept->assertOk()->assertJsonPath('data.status', 'accepted');
    }

    public function test_a_non_party_cannot_accept_the_move_request(): void
    {
        [$plan, $parent] = $this->makeApprovedPlan();
        $parentToken = $this->tokenFor($parent);

        $create = $this->withHeader('Authorization', "Bearer {$parentToken}")
            ->postJson("/api/v1/plans/{$plan->public_id}/move-requests", [
                'route' => 'move_learner',
                'to_day' => 'wed',
                'to_starts_at' => '16:00',
                'reason' => 'Clashes with a school trip.',
            ]);

        $stranger = $this->makeAccount();
        $strangerToken = $this->tokenFor($stranger);

        $response = $this->withHeader('Authorization', "Bearer {$strangerToken}")
            ->postJson("/api/v1/move-requests/{$create->json('data.id')}/accept");

        $response->assertForbidden();
    }
}
