<?php

namespace Tests\Feature\Core;

use App\Domains\Core\Models\Feedback;
use App\Domains\Core\Models\Session;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers 2024_02_01_000075: "Save draft" must keep payment held — only a
 * submitted feedback row (with notes and a rating) should be reachable
 * (docs/api-contract.md §11).
 */
class FeedbackDraftTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    private function makeSession(): Session
    {
        $learner = $this->makeLearnerProfile();
        $teacher = $this->makeTeacher();

        return Session::create([
            'learner_profile_id' => $learner->id,
            'teacher_id' => $teacher->id,
            'scheduled_at' => now()->subHour(),
            'format' => 'one_on_one',
            'status' => 'completed',
        ]);
    }

    public function test_a_draft_can_be_saved_with_no_notes_or_rating(): void
    {
        $session = $this->makeSession();

        $feedback = Feedback::create([
            'session_id' => $session->id,
            'teacher_id' => $session->teacher_id,
            'learner_profile_id' => $session->learner_profile_id,
            'status' => 'draft',
        ]);

        $this->assertSame('draft', $feedback->fresh()->status);
        $this->assertNull($feedback->fresh()->session_notes);
    }

    public function test_submitting_without_notes_is_rejected(): void
    {
        $session = $this->makeSession();

        $this->expectException(QueryException::class);

        Feedback::create([
            'session_id' => $session->id,
            'teacher_id' => $session->teacher_id,
            'learner_profile_id' => $session->learner_profile_id,
            'status' => 'submitted',
            'progress_rating' => 4,
        ]);
    }

    public function test_submitting_without_a_rating_is_rejected(): void
    {
        $session = $this->makeSession();

        $this->expectException(QueryException::class);

        Feedback::create([
            'session_id' => $session->id,
            'teacher_id' => $session->teacher_id,
            'learner_profile_id' => $session->learner_profile_id,
            'status' => 'submitted',
            'session_notes' => 'Great progress today.',
        ]);
    }

    public function test_submitting_with_notes_and_rating_succeeds(): void
    {
        $session = $this->makeSession();

        $feedback = Feedback::create([
            'session_id' => $session->id,
            'teacher_id' => $session->teacher_id,
            'learner_profile_id' => $session->learner_profile_id,
            'status' => 'submitted',
            'attendance' => 'present',
            'session_notes' => 'Great progress today.',
            'progress_rating' => 5,
        ]);

        $this->assertSame('submitted', $feedback->fresh()->status);
    }
}
