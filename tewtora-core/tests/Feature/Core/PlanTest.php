<?php

namespace Tests\Feature\Core;

use App\Domains\Core\Models\Plan;
use App\Domains\Core\Models\Session;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers 2024_02_01_000070/000071/000072: core.plans, the
 * status-conditioned renews_at CHECK, a learner holding multiple
 * concurrent plans, and sessions.plan_id's ON DELETE SET NULL
 * (docs/api-gap-analysis.md §4 — the single biggest gap in the original
 * schema).
 */
class PlanTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    private function makePlan(array $overrides = []): Plan
    {
        $learner = $overrides['learner_profile_id'] ?? $this->makeLearnerProfile()->id;
        $teacher = $overrides['teacher_id'] ?? $this->makeTeacher()->id;
        $subject = $overrides['subject_id'] ?? $this->makeSubject()->id;

        return Plan::create(array_merge([
            'learner_profile_id' => $learner,
            'teacher_id' => $teacher,
            'subject_id' => $subject,
            'format' => 'one_on_one',
            'days' => ['tue', 'thu'],
            'time_of_day' => '16:00',
            'status' => 'active',
            'rate_minor' => 500000,
            'sessions_per_month' => 8,
            'sessions_remaining' => 8,
            'renews_at' => now()->addMonth(),
            'reference' => 'TWT-'.uniqid(),
        ], $overrides));
    }

    public function test_an_active_plan_requires_a_renews_at(): void
    {
        $this->expectException(QueryException::class);

        $this->makePlan(['status' => 'active', 'renews_at' => null]);
    }

    public function test_a_paused_plan_does_not_require_a_renews_at(): void
    {
        $plan = $this->makePlan(['status' => 'paused', 'renews_at' => null]);

        $this->assertSame('paused', $plan->fresh()->status);
    }

    public function test_a_learner_can_hold_multiple_concurrent_plans(): void
    {
        $learner = $this->makeLearnerProfile();

        $mathsPlan = $this->makePlan(['learner_profile_id' => $learner->id]);
        $englishPlan = $this->makePlan(['learner_profile_id' => $learner->id]);

        $this->assertSame(2, Plan::where('learner_profile_id', $learner->id)->count());
        $this->assertNotSame($mathsPlan->id, $englishPlan->id);
    }

    public function test_deleting_a_plan_nulls_out_plan_id_on_its_sessions_instead_of_blocking(): void
    {
        $plan = $this->makePlan();

        $session = Session::create([
            'plan_id' => $plan->id,
            'learner_profile_id' => $plan->learner_profile_id,
            'teacher_id' => $plan->teacher_id,
            'scheduled_at' => now()->addWeek(),
            'format' => 'one_on_one',
        ]);

        $plan->delete();

        $this->assertNull($session->fresh()->plan_id);
    }

    public function test_plan_reference_must_be_unique(): void
    {
        $this->makePlan(['reference' => 'TWT-DUPLICATE']);

        $this->expectException(QueryException::class);

        $this->makePlan(['reference' => 'TWT-DUPLICATE']);
    }
}
