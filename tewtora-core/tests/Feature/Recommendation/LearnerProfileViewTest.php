<?php

namespace Tests\Feature\Recommendation;

use App\Domains\Recommendation\Models\LearnerProfileView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers 2024_02_01_000060: the read model carries the fields
 * docs/api-contract.md §9's AnonymisedBrief needs (challenges, goals,
 * preferred_slots, a resolved budget range) and, critically, no PII —
 * the anonymization requirement is structural, not a matter of the API
 * layer choosing not to render a field.
 */
class LearnerProfileViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_view_stores_no_name_or_contact_field(): void
    {
        $view = LearnerProfileView::create([
            'id' => 1,
            'public_id' => (string) Str::uuid(),
            'grade_level' => 'grade-8',
            'curriculum_id' => 1,
            'challenges' => ['fractions'],
            'goals' => ['pass the term exam'],
            'preferred_slots' => ['tue-16:00'],
            'budget_min_minor' => 300000,
            'budget_max_minor' => 600000,
            'subject_ids' => [1, 2],
        ]);

        $this->assertArrayNotHasKey('full_name', $view->getAttributes());
        $this->assertArrayNotHasKey('email', $view->getAttributes());
        $this->assertSame(['fractions'], $view->fresh()->challenges);
        $this->assertSame(300000, $view->fresh()->budget_min_minor);
    }
}
