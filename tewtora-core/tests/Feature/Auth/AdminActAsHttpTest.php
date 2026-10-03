<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * docs/api-contract.md §18: "Must be read-only — this token/session should
 * not be able to call any 🔒 mutating endpoint elsewhere in this
 * document... that has to be true at the API layer, not just hidden in
 * the UI." The most security-critical test in this codebase.
 */
class AdminActAsHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    public function test_act_as_requires_a_reason_from_the_allowed_list(): void
    {
        $admin = $this->makeAccount(['account_type' => 'admin']);
        $target = $this->makeAccount();
        $token = $this->tokenFor($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/internal/accounts/{$target->public_id}/act-as", [
                'reason' => 'just because',
            ]);

        // Not assertJsonValidationErrors() — this app's error envelope puts
        // field errors under error.fields, not Laravel's default top-level
        // errors key.
        $response->assertStatus(422)
            ->assertJsonPath('error.fields.reason.0', 'The selected reason is invalid.');
    }

    public function test_an_act_as_token_cannot_call_a_mutating_endpoint(): void
    {
        $admin = $this->makeAccount(['account_type' => 'admin']);
        $target = $this->makeAccount();
        $adminToken = $this->tokenFor($admin);

        $actAs = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/v1/internal/accounts/{$target->public_id}/act-as", [
                'reason' => 'Parent asked support for help',
            ]);

        $actAsToken = $actAs->json('token');

        // The 'sanctum' guard caches its resolved user for the life of the
        // guard instance, which outlives a single simulated request within
        // a test method — without forgetting it, this second request would
        // silently keep authenticating as $admin (the first token used),
        // never actually exercising the act-as token at all.
        Auth::forgetGuards();

        // Try to mutate something as the acted-as account — must be
        // rejected purely because the token is read-only, regardless of
        // whether the underlying action would otherwise have been allowed.
        $response = $this->withHeader('Authorization', "Bearer {$actAsToken}")
            ->postJson('/api/v1/auth/switch-profile', ['learner_id' => 'does-not-matter']);

        $response->assertForbidden();
    }

    public function test_an_act_as_token_can_still_make_read_requests(): void
    {
        $admin = $this->makeAccount(['account_type' => 'admin']);
        $target = $this->makeAccount();
        $adminToken = $this->tokenFor($admin);

        $actAs = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/v1/internal/accounts/{$target->public_id}/act-as", [
                'reason' => 'Parent asked support for help',
            ]);

        Auth::forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer {$actAs->json('token')}")
            ->getJson('/api/v1/auth/session');

        $response->assertOk();
    }
}
