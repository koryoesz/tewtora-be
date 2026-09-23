<?php

namespace Tests\Feature\Auth;

use App\Domains\Auth\Models\Assessment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers 2024_02_01_000054_alter_auth_assessments_add_draft_support: the
 * relaxed NOT NULLs, the status-conditioned CHECK, and the rebuilt consent
 * trigger (docs/api-contract.md §2 / docs/api-gap-analysis.md §2).
 */
class AssessmentDraftTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    public function test_a_draft_can_be_saved_with_no_answers_yet(): void
    {
        $learner = $this->makeLearnerProfile();

        $assessment = Assessment::create([
            'learner_profile_id' => $learner->id,
            'status' => 'draft',
        ]);

        $this->assertSame('draft', $assessment->fresh()->status);
        $this->assertNull($assessment->fresh()->learning_goals);
    }

    public function test_submitting_without_learning_goals_is_rejected(): void
    {
        $learner = $this->makeLearnerProfile();

        $this->expectException(QueryException::class);

        Assessment::create([
            'learner_profile_id' => $learner->id,
            'status' => 'submitted',
            'budget_tier' => 'standard',
            'preferred_format' => 'one_on_one',
            'session_frequency' => 'weekly',
            'availability' => [],
        ]);
    }

    public function test_submitting_with_learning_goals_succeeds(): void
    {
        $learner = $this->makeLearnerProfile();

        $assessment = Assessment::create([
            'learner_profile_id' => $learner->id,
            'status' => 'submitted',
            'learning_goals' => ['improve algebra'],
            'budget_tier' => 'standard',
            'preferred_format' => 'one_on_one',
            'session_frequency' => 'weekly',
            'availability' => [],
        ]);

        $this->assertSame('submitted', $assessment->fresh()->status);
    }

    public function test_draft_autosave_does_not_require_consent_for_a_child_profile(): void
    {
        $owner = $this->makeAccount();
        $child = $this->makeLearnerProfile([
            'owner_account_id' => $owner->id,
            'profile_type' => 'child',
        ]);

        // Must not throw: a draft save is not the consent-enforcement point.
        $assessment = Assessment::create([
            'learner_profile_id' => $child->id,
            'status' => 'draft',
            'consent_given' => false,
        ]);

        $this->assertSame('draft', $assessment->fresh()->status);
    }

    public function test_submitting_a_child_profile_assessment_without_consent_is_rejected(): void
    {
        $owner = $this->makeAccount();
        $child = $this->makeLearnerProfile([
            'owner_account_id' => $owner->id,
            'profile_type' => 'child',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/consent_required_for_child_profile/');

        Assessment::create([
            'learner_profile_id' => $child->id,
            'status' => 'submitted',
            'consent_given' => false,
            'learning_goals' => ['improve algebra'],
            'budget_tier' => 'standard',
            'preferred_format' => 'one_on_one',
            'session_frequency' => 'weekly',
            'availability' => [],
        ]);
    }

    public function test_submitting_a_child_profile_assessment_with_consent_succeeds(): void
    {
        $owner = $this->makeAccount();
        $child = $this->makeLearnerProfile([
            'owner_account_id' => $owner->id,
            'profile_type' => 'child',
        ]);

        $assessment = Assessment::create([
            'learner_profile_id' => $child->id,
            'status' => 'submitted',
            'consent_given' => true,
            'learning_goals' => ['improve algebra'],
            'budget_tier' => 'standard',
            'preferred_format' => 'one_on_one',
            'session_frequency' => 'weekly',
            'availability' => [],
        ]);

        $this->assertSame('submitted', $assessment->fresh()->status);
    }

    public function test_submitting_an_own_profile_assessment_never_needs_consent(): void
    {
        $learner = $this->makeLearnerProfile(['profile_type' => 'own']);

        $assessment = Assessment::create([
            'learner_profile_id' => $learner->id,
            'status' => 'submitted',
            'consent_given' => false,
            'learning_goals' => ['pass the exam'],
            'budget_tier' => 'standard',
            'preferred_format' => 'one_on_one',
            'session_frequency' => 'weekly',
            'availability' => [],
        ]);

        $this->assertSame('submitted', $assessment->fresh()->status);
    }
}
