<?php

namespace Tests\Feature\Recommendation;

use App\Domains\Recommendation\Models\RecruitmentTarget;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers 2024_02_01_000061: one recruitment target per subject/curriculum
 * combination (docs/api-contract.md §15).
 */
class RecruitmentTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_target_per_subject_curriculum_pair(): void
    {
        RecruitmentTarget::create(['subject_id' => 1, 'curriculum_id' => 1]);

        $this->expectException(QueryException::class);

        RecruitmentTarget::create(['subject_id' => 1, 'curriculum_id' => 1]);
    }

    public function test_bookings_paused_defaults_to_false(): void
    {
        $target = RecruitmentTarget::create(['subject_id' => 2, 'curriculum_id' => 1]);

        $this->assertFalse($target->fresh()->bookings_paused);
    }
}
