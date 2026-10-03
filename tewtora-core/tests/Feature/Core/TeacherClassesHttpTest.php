<?php

namespace Tests\Feature\Core;

use App\Domains\Core\Models\Feedback;
use App\Domains\Core\Models\Plan;
use App\Domains\Core\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers docs/needed-endpoints-classes.md §3: a teacher-side aggregate
 * across every plan they teach, mirroring /plans/:id/next-sessions and
 * /plans/:id/history's own per-plan shape (TeacherClassController).
 */
class TeacherClassesHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    public function test_a_teacher_sees_their_own_scheduled_sessions_as_next_sessions(): void
    {
        $teacher = $this->makeTeacher();
        $this->linkTeacherToCore($teacher);
        $learner = $this->makeLearnerProfile();

        Session::create([
            'learner_profile_id' => $learner->id,
            'teacher_id' => $teacher->id,
            'scheduled_at' => now()->addDay(),
            'format' => 'one_on_one',
            'status' => 'scheduled',
        ]);

        $token = $this->tokenFor($teacher->account);
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/teachers/{$teacher->public_id}/next-sessions");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertFalse($response->json('data.0.is_live'));
    }

    public function test_a_teacher_sees_a_completed_session_in_their_history_with_its_feedback_note(): void
    {
        $teacher = $this->makeTeacher();
        $this->linkTeacherToCore($teacher);
        $learner = $this->makeLearnerProfile();

        $session = Session::create([
            'learner_profile_id' => $learner->id,
            'teacher_id' => $teacher->id,
            'scheduled_at' => now()->subDay(),
            'format' => 'one_on_one',
            'status' => 'completed',
        ]);

        Feedback::create([
            'session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'learner_profile_id' => $learner->id,
            'status' => 'submitted',
            'session_notes' => 'Great progress on fractions today.',
            'progress_rating' => 4,
        ]);

        $token = $this->tokenFor($teacher->account);
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/teachers/{$teacher->public_id}/history");

        $response->assertOk()
            ->assertJsonPath('data.0.note', 'Great progress on fractions today.')
            ->assertJsonPath('data.0.score_out_of_5', 4);
    }

    public function test_a_next_session_tied_to_a_plan_includes_the_plan_id(): void
    {
        $teacher = $this->makeTeacher();
        $this->linkTeacherToCore($teacher);
        $learner = $this->makeLearnerProfile();
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
        ])->refresh();

        Session::create([
            'plan_id' => $plan->id,
            'learner_profile_id' => $learner->id,
            'teacher_id' => $teacher->id,
            'scheduled_at' => now()->addDay(),
            'format' => 'one_on_one',
            'status' => 'scheduled',
        ]);

        $token = $this->tokenFor($teacher->account);
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/teachers/{$teacher->public_id}/next-sessions");

        $response->assertOk()->assertJsonPath('data.0.plan_id', $plan->public_id);
    }

    public function test_a_teacher_can_list_their_own_plans(): void
    {
        $teacher = $this->makeTeacher();
        $this->linkTeacherToCore($teacher);
        $learner = $this->makeLearnerProfile();
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

        $token = $this->tokenFor($teacher->account);
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/teachers/{$teacher->public_id}/plans");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_teacher_cannot_list_another_teachers_classes(): void
    {
        $teacher = $this->makeTeacher();
        $this->linkTeacherToCore($teacher);
        $otherTeacher = $this->makeTeacher();
        $this->linkTeacherToCore($otherTeacher);

        $token = $this->tokenFor($otherTeacher->account);
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/teachers/{$teacher->public_id}/next-sessions");

        $response->assertForbidden();
    }

    public function test_a_parent_cannot_list_a_teachers_classes(): void
    {
        $teacher = $this->makeTeacher();
        $this->linkTeacherToCore($teacher);
        $parent = $this->makeAccount();

        $token = $this->tokenFor($parent);
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/teachers/{$teacher->public_id}/next-sessions");

        $response->assertForbidden();
    }
}
