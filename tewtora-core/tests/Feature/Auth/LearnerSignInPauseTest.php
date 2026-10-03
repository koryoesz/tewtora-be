<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers docs/needed-endpoints-child-pin-and-budget-schedule.md §5:
 * pausing a child's own username+PIN sign-in, independently of
 * archive/restore (2024_02_01_000104).
 */
class LearnerSignInPauseTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    private function makeChildWithPin(array $learnerOverrides = []): array
    {
        $parent = $this->makeAccount();
        $child = $this->makeAccount(['account_type' => 'child', 'username' => 'ada-'.uniqid()]);
        $learner = $this->makeLearnerProfile(array_merge([
            'owner_account_id' => $parent->id,
            'linked_login_account_id' => $child->id,
            'profile_type' => 'child',
            'pin_hash' => password_hash('1234', PASSWORD_ARGON2ID),
        ], $learnerOverrides));

        return [$parent, $child, $learner];
    }

    public function test_sign_in_paused_defaults_to_false_on_a_new_profile(): void
    {
        $parent = $this->makeAccount();
        $token = $this->tokenFor($parent);
        $this->makeCurriculum(['code' => 'ib']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/learners', [
            'name' => 'Ada',
            'grade_level' => 'grade-7',
            'curriculum' => 'ib',
        ]);

        $response->assertCreated()->assertJsonPath('data.sign_in_paused', false);
    }

    public function test_the_owning_parent_can_pause_and_resume_sign_in(): void
    {
        [$parent, , $learner] = $this->makeChildWithPin();
        $token = $this->tokenFor($parent);

        $pause = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/learners/{$learner->public_id}/pause-sign-in");
        $pause->assertOk()->assertJsonPath('data.sign_in_paused', true);

        $resume = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/learners/{$learner->public_id}/resume-sign-in");
        $resume->assertOk()->assertJsonPath('data.sign_in_paused', false);
    }

    public function test_a_linked_child_login_cannot_pause_its_own_sign_in(): void
    {
        [, $child, $learner] = $this->makeChildWithPin();
        $token = $this->tokenFor($child);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/learners/{$learner->public_id}/pause-sign-in");

        $response->assertForbidden();
    }

    public function test_a_stranger_cannot_pause_someone_elses_child(): void
    {
        [, , $learner] = $this->makeChildWithPin();
        $stranger = $this->makeAccount();
        $token = $this->tokenFor($stranger);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/learners/{$learner->public_id}/pause-sign-in");

        // The ownedByAccount global scope filters this out before the
        // policy even runs — a 404, not a 403 (matches
        // LearnerProfileHttpTest's own stranger-view assertion).
        $response->assertNotFound();
    }

    public function test_an_unauthenticated_caller_cannot_pause_sign_in(): void
    {
        [, , $learner] = $this->makeChildWithPin();

        $response = $this->postJson("/api/v1/learners/{$learner->public_id}/pause-sign-in");

        $response->assertUnauthorized();
    }

    public function test_a_paused_childs_login_fails_even_with_the_correct_pin(): void
    {
        [$parent, $child, $learner] = $this->makeChildWithPin();
        $parentToken = $this->tokenFor($parent);

        $this->withHeader('Authorization', "Bearer {$parentToken}")
            ->postJson("/api/v1/learners/{$learner->public_id}/pause-sign-in")
            ->assertOk();

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $child->username,
            'pin' => '1234',
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 'child_sign_in_paused');
    }

    public function test_an_unpaused_childs_login_still_works(): void
    {
        [, $child] = $this->makeChildWithPin();

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $child->username,
            'pin' => '1234',
        ]);

        $response->assertOk()->assertJsonStructure(['token']);
    }

    public function test_resuming_sign_in_lets_the_child_log_in_again(): void
    {
        [$parent, $child, $learner] = $this->makeChildWithPin();
        $parentToken = $this->tokenFor($parent);

        $this->withHeader('Authorization', "Bearer {$parentToken}")
            ->postJson("/api/v1/learners/{$learner->public_id}/pause-sign-in")
            ->assertOk();
        $this->withHeader('Authorization', "Bearer {$parentToken}")
            ->postJson("/api/v1/learners/{$learner->public_id}/resume-sign-in")
            ->assertOk();

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $child->username,
            'pin' => '1234',
        ]);

        $response->assertOk()->assertJsonStructure(['token']);
    }

    public function test_pausing_sign_in_is_independent_of_archiving(): void
    {
        [$parent, , $learner] = $this->makeChildWithPin();
        $token = $this->tokenFor($parent);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/learners/{$learner->public_id}/pause-sign-in")
            ->assertOk();

        // Archiving on top of an already-paused profile must not touch
        // sign_in_paused, and the profile must still read as paused.
        $archive = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/learners/{$learner->public_id}/archive");

        $archive->assertOk()
            ->assertJsonPath('data.archived', true)
            ->assertJsonPath('data.sign_in_paused', true);
    }
}
