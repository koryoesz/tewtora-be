<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/** HTTP-level companion to AssessmentDraftTest's model-level CHECK/trigger coverage — exercises ConsentRequiredIfMinor and the endpoint layer. */
class AssessmentSubmitHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    private function submitPayload(): array
    {
        return [
            'learning_goals' => ['pass the term exam'],
            'budget_tier' => 'standard',
            'preferred_format' => 'one_on_one',
            'session_frequency' => 'weekly',
            'availability' => [],
        ];
    }

    public function test_submitting_a_child_assessment_without_consent_is_rejected_with_422(): void
    {
        $parent = $this->makeAccount();
        $child = $this->makeLearnerProfile(['owner_account_id' => $parent->id, 'profile_type' => 'child']);
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/learners/{$child->public_id}/assessment/submit", [
                ...$this->submitPayload(),
                'consent_given' => false,
            ]);

        // Not assertJsonValidationErrors() — this app's error envelope puts
        // field errors under error.fields, not Laravel's default top-level
        // errors key that helper looks for.
        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.fields.consent_given.0', 'Parental consent is required for a child profile.');
    }

    public function test_submitting_a_child_assessment_with_consent_succeeds(): void
    {
        $parent = $this->makeAccount();
        $child = $this->makeLearnerProfile(['owner_account_id' => $parent->id, 'profile_type' => 'child']);
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/learners/{$child->public_id}/assessment/submit", [
                ...$this->submitPayload(),
                'consent_given' => true,
            ]);

        $response->assertOk()->assertJsonPath('assessment.status', 'submitted');
    }

    /** A learning goal is not required to submit — see 2024_02_01_000098. */
    public function test_submitting_without_a_learning_goal_succeeds(): void
    {
        $learner = $this->makeLearnerProfile();
        $token = $this->tokenFor($learner->owner);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/learners/{$learner->public_id}/assessment/submit", [
                'budget_tier' => 'standard',
                'preferred_format' => 'one_on_one',
                'session_frequency' => 'weekly',
                'availability' => [],
            ]);

        $response->assertOk()->assertJsonPath('assessment.status', 'submitted');
    }

    public function test_a_stranger_cannot_submit_an_assessment_for_someone_elses_learner(): void
    {
        $learner = $this->makeLearnerProfile();
        $stranger = $this->makeAccount();
        $token = $this->tokenFor($stranger);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/learners/{$learner->public_id}/assessment/submit", $this->submitPayload());

        // LearnerProfile's global scope filters the route-model-binding
        // query itself, before SubmitAssessmentRequest::authorize() runs
        // — a 404, not a 403 (same fail-closed pattern as
        // LearnerProfileHttpTest's stranger case).
        $response->assertNotFound();
    }
}
