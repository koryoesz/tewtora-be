<?php

namespace Tests\Feature\Core;

use App\Domains\Core\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

class PlanHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    private function makePlanFor($learner, $teacher, array $overrides = []): Plan
    {
        $subject = $this->makeSubject();

        // public_id is DB-generated (DEFAULT (UUID())) — create()'s
        // in-memory model doesn't know it without a refresh, which every
        // route in these tests needs (they're keyed by public_id).
        return Plan::create(array_merge([
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
        ], $overrides))->refresh();
    }

    public function test_the_owning_parent_can_pause_their_plan(): void
    {
        $parent = $this->makeAccount();
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id]);
        $this->linkLearnerToCore($learner);
        $teacher = $this->makeTeacher();
        $plan = $this->makePlanFor($learner, $teacher);
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/plans/{$plan->public_id}/pause");

        $response->assertOk()->assertJsonPath('data.status', 'paused');
    }

    public function test_a_stranger_cannot_pause_someone_elses_plan(): void
    {
        $learner = $this->makeLearnerProfile();
        $this->linkLearnerToCore($learner);
        $teacher = $this->makeTeacher();
        $plan = $this->makePlanFor($learner, $teacher);
        $stranger = $this->makeAccount();
        $token = $this->tokenFor($stranger);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/plans/{$plan->public_id}/pause");

        $response->assertForbidden();
    }

    public function test_pausing_an_already_paused_plan_is_rejected(): void
    {
        $parent = $this->makeAccount();
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id]);
        $this->linkLearnerToCore($learner);
        $teacher = $this->makeTeacher();
        $plan = $this->makePlanFor($learner, $teacher, ['status' => 'paused', 'renews_at' => null]);
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/plans/{$plan->public_id}/pause");

        $response->assertStatus(409)->assertJsonPath('error.code', 'invalid_plan_transition');
    }

    public function test_rebook_carries_over_remaining_sessions_when_paused(): void
    {
        $parent = $this->makeAccount();
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id]);
        $this->linkLearnerToCore($learner);
        $teacher = $this->makeTeacher();
        $plan = $this->makePlanFor($learner, $teacher, [
            'status' => 'paused',
            'renews_at' => null,
            'sessions_remaining' => 2,
        ]);
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/plans/{$plan->public_id}/rebook", ['session_count' => 4]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.sessions_remaining', 6);
    }

    public function test_ending_a_plan_returns_the_refund_owed(): void
    {
        $parent = $this->makeAccount();
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id]);
        $this->linkLearnerToCore($learner);
        $teacher = $this->makeTeacher();
        $plan = $this->makePlanFor($learner, $teacher, ['sessions_remaining' => 3, 'rate_minor' => 500000]);
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/plans/{$plan->public_id}");

        $response->assertOk()->assertJsonPath('refund_minor', 1500000);
    }
}
