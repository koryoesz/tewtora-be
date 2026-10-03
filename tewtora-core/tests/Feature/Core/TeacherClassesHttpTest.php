<?php

namespace Tests\Feature\Core;

use App\Domains\Core\Models\Feedback;
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
