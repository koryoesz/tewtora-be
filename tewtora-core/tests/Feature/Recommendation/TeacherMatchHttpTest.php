<?php

namespace Tests\Feature\Recommendation;

use App\Domains\Recommendation\Models\LearnerProfileView;
use App\Domains\Recommendation\Models\TeacherMatch;
use App\Domains\Recommendation\Models\TeacherProfileView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

class TeacherMatchHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    private function makeMatch(): array
    {
        $teacherAccount = $this->makeAccount(['account_type' => 'teacher']);
        $teacherView = TeacherProfileView::create([
            'id' => 1,
            'public_id' => (string) Str::uuid(),
            'account_id' => $teacherAccount->id,
            'verification_status' => 'approved',
        ]);
        $learnerView = LearnerProfileView::create([
            'id' => 1,
            'public_id' => (string) Str::uuid(),
            'grade_level' => 'grade-6',
            'curriculum_id' => 1,
        ]);

        $match = TeacherMatch::create([
            'learner_profile_id' => $learnerView->id,
            'teacher_id' => $teacherView->id,
            'status' => 'proposed',
        ]);

        return [$match, $teacherAccount, $teacherView];
    }

    public function test_the_teacher_can_accept_their_own_pending_match(): void
    {
        [$match, $teacherAccount] = $this->makeMatch();
        $token = $this->tokenFor($teacherAccount);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/match-requests/{$match->public_id}/accept");

        $response->assertOk()->assertJsonPath('status', 'accepted');
    }

    public function test_a_different_teacher_cannot_respond_to_someone_elses_match(): void
    {
        [$match] = $this->makeMatch();
        $otherTeacher = $this->makeAccount(['account_type' => 'teacher']);
        $token = $this->tokenFor($otherTeacher);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/match-requests/{$match->public_id}/accept");

        $response->assertForbidden();
    }

    public function test_declining_requires_a_reason(): void
    {
        [$match, $teacherAccount] = $this->makeMatch();
        $token = $this->tokenFor($teacherAccount);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/match-requests/{$match->public_id}/decline", []);

        $response->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_a_match_cannot_be_accepted_twice(): void
    {
        [$match, $teacherAccount] = $this->makeMatch();
        $token = $this->tokenFor($teacherAccount);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/v1/match-requests/{$match->public_id}/accept");
        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/v1/match-requests/{$match->public_id}/accept");

        $response->assertStatus(409)->assertJsonPath('error.code', 'match_already_decided');
    }

    public function test_the_teachers_pending_inbox_excludes_other_teachers_matches(): void
    {
        [$match, $teacherAccount, $teacherView] = $this->makeMatch();
        $token = $this->tokenFor($teacherAccount);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/teachers/{$teacherView->public_id}/match-requests");

        $response->assertOk()->assertJsonCount(1, 'data');
    }
}
