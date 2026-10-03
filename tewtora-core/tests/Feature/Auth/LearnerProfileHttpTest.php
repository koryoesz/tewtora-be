<?php

namespace Tests\Feature\Auth;

use App\Domains\Core\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

class LearnerProfileHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    public function test_a_parent_can_create_a_child_profile(): void
    {
        $parent = $this->makeAccount();
        $this->makeCurriculum(['code' => 'ib']);
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/learners', [
            'name' => 'Ada',
            'grade_level' => 'grade-7',
            'curriculum' => 'ib',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Ada');
    }

    public function test_an_independent_student_cannot_add_a_child(): void
    {
        $account = $this->makeAccount(['account_type' => 'independent_student']);
        $this->makeCurriculum(['code' => 'ib']);
        $token = $this->tokenFor($account);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/learners', [
            'name' => 'Ada',
            'grade_level' => 'grade-7',
            'curriculum' => 'ib',
        ]);

        $response->assertForbidden();
    }

    public function test_a_stranger_cannot_view_someone_elses_learner_profile(): void
    {
        $learner = $this->makeLearnerProfile();
        $stranger = $this->makeAccount();
        $token = $this->tokenFor($stranger);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/learners/{$learner->public_id}");

        // The global scope on LearnerProfile filters this out before the
        // policy even runs — a 404, not a 403 (fails closed, doesn't
        // confirm the record exists).
        $response->assertNotFound();
    }

    public function test_a_linked_child_login_cannot_rename_the_profile(): void
    {
        $parent = $this->makeAccount();
        $child = $this->makeAccount(['account_type' => 'child']);
        $learner = $this->makeLearnerProfile([
            'owner_account_id' => $parent->id,
            'linked_login_account_id' => $child->id,
            'profile_type' => 'child',
        ]);
        $token = $this->tokenFor($child);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/learners/{$learner->public_id}", ['name' => 'New Name']);

        $response->assertForbidden();
    }

    public function test_removing_a_child_with_an_active_plan_is_refused(): void
    {
        $parent = $this->makeAccount();
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id]);
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        Plan::create([
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
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/learners/{$learner->public_id}/archive");

        $response->assertStatus(409)->assertJsonPath('error.code', 'learner_has_active_plan');
    }

    public function test_removing_a_child_with_only_a_paused_plan_succeeds(): void
    {
        $parent = $this->makeAccount();
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id]);
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        Plan::create([
            'learner_profile_id' => $learner->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'format' => 'one_on_one',
            'days' => ['tue'],
            'time_of_day' => '16:00',
            'status' => 'paused',
            'rate_minor' => 500000,
            'sessions_per_month' => 4,
            'sessions_remaining' => 4,
            'renews_at' => null,
            'reference' => 'TWT-'.uniqid(),
        ]);
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/learners/{$learner->public_id}/archive");

        $response->assertOk()->assertJsonPath('data.archived', true);
    }
}
