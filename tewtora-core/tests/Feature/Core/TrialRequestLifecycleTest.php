<?php

namespace Tests\Feature\Core;

use App\Domains\Core\Models\Session;
use App\Domains\Core\Support\TrialDeclineReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers docs/needed-endpoints-trial-requests.md: decline-with-reason (two
 * audience-dependent wordings), counter-offering an alternate time, the
 * family responding specifically to that countered time, withdrawing after
 * a counter, and a trial's post-session note riding the existing
 * /sessions/:id/feedback endpoint rather than needing a new one.
 */
class TrialRequestLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    private function createTrialRequest(): array
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

        return [$parent, $teacher, $create->json('data.id')];
    }

    public function test_declining_with_a_reason_shows_the_family_the_softened_wording(): void
    {
        [$parent, $teacher, $trialRequestId] = $this->createTrialRequest();

        $this->withHeader('Authorization', "Bearer {$this->tokenFor($teacher->account)}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", [
                'decision' => 'decline',
                'reason' => 'budget',
            ])->assertOk();

        $familyView = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->getJson("/api/v1/trial-requests/{$trialRequestId}");

        $familyView->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.decline_reason', 'budget');
        $this->assertSame(
            TrialDeclineReason::familyWording('budget'),
            $familyView->json('data.decline_reason_message')
        );
    }

    public function test_declining_with_a_reason_shows_the_teacher_their_own_candid_wording(): void
    {
        [, $teacher, $trialRequestId] = $this->createTrialRequest();
        $teacherToken = $this->tokenFor($teacher->account);

        $this->withHeader('Authorization', "Bearer {$teacherToken}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", [
                'decision' => 'decline',
                'reason' => 'budget',
            ])->assertOk();

        $teacherView = $this->withHeader('Authorization', "Bearer {$teacherToken}")
            ->getJson("/api/v1/trial-requests/{$trialRequestId}");

        $teacherWording = TrialDeclineReason::teacherWording('budget');
        $familyWording = TrialDeclineReason::familyWording('budget');

        $this->assertSame($teacherWording, $teacherView->json('data.decline_reason_message'));
        $this->assertNotSame($familyWording, $teacherWording, 'the two wordings must differ for this test to mean anything');
    }

    /** A reason is optional — the pre-existing plain {"decision":"decline"} shape (TrialRequestHttpTest) must keep working unchanged. */
    public function test_declining_without_a_reason_still_works(): void
    {
        [, $teacher, $trialRequestId] = $this->createTrialRequest();

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($teacher->account)}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", ['decision' => 'decline']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.decline_reason', null)
            ->assertJsonPath('data.decline_reason_message', null);
    }

    public function test_an_invalid_decline_reason_is_rejected(): void
    {
        [, $teacher, $trialRequestId] = $this->createTrialRequest();

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($teacher->account)}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", [
                'decision' => 'decline',
                'reason' => 'not-a-real-reason',
            ]);

        $response->assertStatus(422);
    }

    public function test_a_counter_offer_sets_countered_starts_at_and_status(): void
    {
        [$parent, $teacher, $trialRequestId] = $this->createTrialRequest();
        $altStartsAt = now()->addDays(2)->toIso8601String();

        $this->withHeader('Authorization', "Bearer {$this->tokenFor($teacher->account)}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", [
                'decision' => 'counter',
                'alt_starts_at' => $altStartsAt,
            ])->assertOk();

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->getJson("/api/v1/trial-requests/{$trialRequestId}");

        $response->assertOk()->assertJsonPath('data.status', 'countered');
        $this->assertNotNull($response->json('data.countered_starts_at'));
    }

    public function test_the_family_can_accept_a_countered_time_and_a_session_is_created_for_it(): void
    {
        [$parent, $teacher, $trialRequestId] = $this->createTrialRequest();
        $altStartsAt = now()->addDays(2)->startOfMinute();

        $this->withHeader('Authorization', "Bearer {$this->tokenFor($teacher->account)}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", [
                'decision' => 'counter',
                'alt_starts_at' => $altStartsAt->toIso8601String(),
            ])->assertOk();

        $parentToken = $this->tokenFor($parent);
        $accept = $this->withHeader('Authorization', "Bearer {$parentToken}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", ['decision' => 'accept']);

        $accept->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertNotNull($accept->json('data.session_id'));

        $session = Session::where('public_id', $accept->json('data.session_id'))->firstOrFail();
        $this->assertTrue($session->scheduled_at->equalTo($altStartsAt));
        $this->assertTrue((bool) $session->is_trial);
    }

    public function test_the_family_can_decline_a_countered_time(): void
    {
        [$parent, $teacher, $trialRequestId] = $this->createTrialRequest();

        $this->withHeader('Authorization', "Bearer {$this->tokenFor($teacher->account)}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", [
                'decision' => 'counter',
                'alt_starts_at' => now()->addDays(2)->toIso8601String(),
            ])->assertOk();

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", ['decision' => 'decline']);

        $response->assertOk()->assertJsonPath('data.status', 'declined');
    }

    public function test_the_teacher_cannot_respond_to_their_own_countered_offer(): void
    {
        [, $teacher, $trialRequestId] = $this->createTrialRequest();
        $teacherToken = $this->tokenFor($teacher->account);

        $this->withHeader('Authorization', "Bearer {$teacherToken}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", [
                'decision' => 'counter',
                'alt_starts_at' => now()->addDays(2)->toIso8601String(),
            ])->assertOk();

        $response = $this->withHeader('Authorization', "Bearer {$teacherToken}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", ['decision' => 'accept']);

        $response->assertForbidden();
    }

    public function test_a_second_counter_is_rejected(): void
    {
        [$parent, $teacher, $trialRequestId] = $this->createTrialRequest();

        $this->withHeader('Authorization', "Bearer {$this->tokenFor($teacher->account)}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", [
                'decision' => 'counter',
                'alt_starts_at' => now()->addDays(2)->toIso8601String(),
            ])->assertOk();

        // The family trying to "counter" a counter isn't a valid decision
        // against an already-countered request.
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", [
                'decision' => 'counter',
                'alt_starts_at' => now()->addDays(3)->toIso8601String(),
            ]);

        $response->assertStatus(422);
    }

    public function test_a_family_can_withdraw_after_a_counter_offer(): void
    {
        [$parent, $teacher, $trialRequestId] = $this->createTrialRequest();

        $this->withHeader('Authorization', "Bearer {$this->tokenFor($teacher->account)}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", [
                'decision' => 'counter',
                'alt_starts_at' => now()->addDays(2)->toIso8601String(),
            ])->assertOk();

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->deleteJson("/api/v1/trial-requests/{$trialRequestId}");

        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_teachers_post_trial_note_is_visible_to_the_family_via_session_feedback(): void
    {
        [$parent, $teacher, $trialRequestId] = $this->createTrialRequest();
        $teacherToken = $this->tokenFor($teacher->account);

        $accept = $this->withHeader('Authorization', "Bearer {$teacherToken}")
            ->postJson("/api/v1/trial-requests/{$trialRequestId}/respond", ['decision' => 'accept']);
        $sessionId = $accept->json('data.session_id');

        $submit = $this->withHeader('Authorization', "Bearer {$teacherToken}")
            ->postJson("/api/v1/sessions/{$sessionId}/feedback", [
                'attendance' => 'present',
                'session_notes' => 'Lovely first session — ready to start regular lessons.',
                'progress_rating' => 5,
            ]);
        // 201, not 200 — Feedback::firstOrNew()->save() in
        // EloquentFeedbackRepository::submit() is a genuine first insert for
        // a trial session (no prior draft), so JsonResource's own
        // wasRecentlyCreated check applies, same as any other first-time
        // resource creation in this codebase.
        $submit->assertStatus(201)->assertJsonPath('data.status', 'submitted');

        $familyRead = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->getJson("/api/v1/sessions/{$sessionId}/feedback");

        $familyRead->assertOk()
            ->assertJsonPath('data.session_notes', 'Lovely first session — ready to start regular lessons.');
    }
}
