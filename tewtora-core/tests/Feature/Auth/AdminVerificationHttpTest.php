<?php

namespace Tests\Feature\Auth;

use App\Domains\Auth\Models\TeacherVerificationCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

class AdminVerificationHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    public function test_a_non_admin_cannot_reach_the_verification_queue(): void
    {
        $parent = $this->makeAccount();
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/internal/verification-applications');

        $response->assertForbidden();
    }

    public function test_approving_with_an_unconfirmed_check_is_rejected(): void
    {
        $admin = $this->makeAccount(['account_type' => 'admin']);
        $teacher = $this->makeTeacher();
        TeacherVerificationCheck::create(['teacher_id' => $teacher->id, 'kind' => 'government_id', 'state' => 'approved']);
        TeacherVerificationCheck::create(['teacher_id' => $teacher->id, 'kind' => 'credentials', 'state' => 'pending']);
        $token = $this->tokenFor($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/internal/verification-applications/{$teacher->public_id}/decide", [
                'decision' => 'approved',
                'note' => 'Looks good so far.',
            ]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'verification_checks_incomplete');
    }

    public function test_approving_requires_a_non_empty_note(): void
    {
        $admin = $this->makeAccount(['account_type' => 'admin']);
        $teacher = $this->makeTeacher();
        $token = $this->tokenFor($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/internal/verification-applications/{$teacher->public_id}/decide", [
                'decision' => 'rejected',
                'note' => '',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('note');
    }

    public function test_approving_with_all_checks_confirmed_succeeds(): void
    {
        $admin = $this->makeAccount(['account_type' => 'admin']);
        $teacher = $this->makeTeacher();
        foreach (['government_id', 'credentials', 'teaching_demo'] as $kind) {
            TeacherVerificationCheck::create(['teacher_id' => $teacher->id, 'kind' => $kind, 'state' => 'approved']);
        }
        $token = $this->tokenFor($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/internal/verification-applications/{$teacher->public_id}/decide", [
                'decision' => 'approved',
                'note' => 'All checks confirmed.',
            ]);

        $response->assertOk()->assertJsonPath('status', 'approved');
    }
}
