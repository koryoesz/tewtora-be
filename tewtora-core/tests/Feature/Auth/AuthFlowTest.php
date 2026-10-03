<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers the login/logout/session/switch-profile flow
 * (docs/api-contract.md §0) end-to-end over real HTTP, including the
 * argon2id hash round-trip (config/hashing.php's HASH_DRIVER).
 */
class AuthFlowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    public function test_login_with_correct_credentials_returns_a_token(): void
    {
        $this->makeAccount(['email' => 'parent@example.test', 'password_hash' => password_hash('secret123', PASSWORD_ARGON2ID)]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'parent@example.test',
            'password' => 'secret123',
        ]);

        $response->assertOk()->assertJsonStructure(['token']);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $this->makeAccount(['email' => 'parent@example.test', 'password_hash' => password_hash('secret123', PASSWORD_ARGON2ID)]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'parent@example.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_session_reports_role_from_the_token_not_the_request(): void
    {
        $teacher = $this->makeAccount(['account_type' => 'teacher']);
        $token = $this->tokenFor($teacher);

        // A role sent in the body must never override what the token
        // resolves to (CLAUDE.md's enforcement note).
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/session?role=admin');

        $response->assertOk()->assertJsonPath('role', 'teacher');
    }

    /**
     * The account's own public_id is a different UUID from the linked
     * auth.teachers row's — GET /teachers/{id} and .../match-requests need
     * the latter, and a teacher session previously had no way to resolve
     * it at all (frontend was hardcoding it, breaking on every reseed).
     */
    public function test_session_includes_the_teacher_profile_id_for_a_teacher(): void
    {
        $profile = $this->makeTeacher();
        $token = $this->tokenFor($profile->account);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/session');

        $response->assertOk()->assertJsonPath('teacher_id', $profile->public_id);
    }

    public function test_session_omits_teacher_id_for_a_non_teacher(): void
    {
        $parent = $this->makeAccount();
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/session');

        $response->assertOk()->assertJsonMissingPath('teacher_id');
    }

    public function test_switch_profile_sets_a_readable_cookie_for_an_owned_learner(): void
    {
        $parent = $this->makeAccount();
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id, 'profile_type' => 'child']);
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/switch-profile', ['learner_id' => $learner->public_id]);

        $response->assertOk();
        // Not the default encrypted=true — the app sets this cookie with
        // raw: true (AuthController::switchProfile) so a Next.js Server
        // Component can read it directly without decrypting it.
        $response->assertCookie('acting_as_learner_id', $learner->public_id, false);
    }

    public function test_switch_profile_to_a_learner_you_do_not_own_is_rejected(): void
    {
        $stranger = $this->makeAccount();
        $learner = $this->makeLearnerProfile();
        $token = $this->tokenFor($stranger);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/switch-profile', ['learner_id' => $learner->public_id]);

        $response->assertForbidden();
    }
}
