<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers docs/api-contract.md §3's trial-request flow end-to-end,
 * including GET /trial-requests/:id (2024_02_01_000099) — previously
 * there was no way for either party to check a request's status, or when
 * the teacher responded, after creating it.
 */
class TrialRequestHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    public function test_the_requester_can_check_a_pending_requests_status(): void
    {
        $parent = $this->makeAccount();
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id]);
        $this->linkLearnerToCore($learner);
        $teacher = $this->makeTeacher();
        $this->linkTeacherToCore($teacher);
        $token = $this->tokenFor($parent);

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/teachers/{$teacher->public_id}/trial-requests", [
                'learner_profile_id' => $learner->id,
                'slot_starts_at' => now()->addDay()->toIso8601String(),
                'duration_minutes' => 30,
            ]);
        $trialRequestId = $create->json('data.id');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/trial-requests/{$trialRequestId}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.responded_at', null)
            ->assertJsonPath('data.session_id', null);
    }

    public function test_accepting_sets_responded_at_and_a_session_id(): void
    {
        $parent = $this->makeAccount();
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id]);
        $this->linkLearnerToCore($learner);
        $teacher = $this->makeTeacher();
        $this->linkTeacherToCore($teacher);

        $create = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->postJson("/api/v1/teachers/{$teacher->public_id}/trial-requests", [
                'learner_profile_id' => $learner->id,
                'slot_starts_at' => now()->addDay()->toIso8601String(),
                'duration_minutes' => 30,
            ]);
        $trialRequestId = $create->json('data.id');

        $teacherToken = $this->tokenFor($teacher->account);
        $this->withHeader('Authorization', "Bearer {$teacherToken}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", ['decision' => 'accept'])
            ->assertOk();

        // Either party can now see it was accepted, and when — checked via
        // the requester's own view, same as the first test.
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->getJson("/api/v1/trial-requests/{$trialRequestId}");

        $response->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertNotNull($response->json('data.responded_at'));
        $this->assertNotNull($response->json('data.session_id'));
    }

    public function test_declining_sets_responded_at_with_no_session(): void
    {
        $parent = $this->makeAccount();
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id]);
        $this->linkLearnerToCore($learner);
        $teacher = $this->makeTeacher();
        $this->linkTeacherToCore($teacher);

        $create = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->postJson("/api/v1/teachers/{$teacher->public_id}/trial-requests", [
                'learner_profile_id' => $learner->id,
                'slot_starts_at' => now()->addDay()->toIso8601String(),
                'duration_minutes' => 30,
            ]);
        $trialRequestId = $create->json('data.id');

        $this->withHeader('Authorization', "Bearer {$this->tokenFor($teacher->account)}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", ['decision' => 'decline'])
            ->assertOk();

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->getJson("/api/v1/trial-requests/{$trialRequestId}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.session_id', null);
        $this->assertNotNull($response->json('data.responded_at'));
    }

    public function test_a_stranger_cannot_view_someone_elses_trial_request(): void
    {
        $parent = $this->makeAccount();
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id]);
        $this->linkLearnerToCore($learner);
        $teacher = $this->makeTeacher();
        $this->linkTeacherToCore($teacher);

        $create = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->postJson("/api/v1/teachers/{$teacher->public_id}/trial-requests", [
                'learner_profile_id' => $learner->id,
                'slot_starts_at' => now()->addDay()->toIso8601String(),
                'duration_minutes' => 30,
            ]);
        $trialRequestId = $create->json('data.id');

        $stranger = $this->makeAccount();

        $this->withHeader('Authorization', "Bearer {$this->tokenFor($stranger)}")
            ->getJson("/api/v1/trial-requests/{$trialRequestId}")
            ->assertForbidden();
    }
}
