<?php

namespace Tests\Feature\Auth;

use App\Domains\Auth\Models\TeacherVerificationCheck;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers 2024_02_01_000051: one check per (teacher, kind), and that the
 * four independent states this table was built for actually persist
 * independently (docs/api-contract.md §3/§14, docs/api-gap-analysis.md §3).
 */
class TeacherVerificationCheckTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    public function test_each_check_kind_tracks_its_own_state(): void
    {
        $teacher = $this->makeTeacher();

        TeacherVerificationCheck::create([
            'teacher_id' => $teacher->id,
            'kind' => 'government_id',
            'state' => 'approved',
        ]);

        TeacherVerificationCheck::create([
            'teacher_id' => $teacher->id,
            'kind' => 'credentials',
            'state' => 'in_review',
        ]);

        TeacherVerificationCheck::create([
            'teacher_id' => $teacher->id,
            'kind' => 'teaching_demo',
            'state' => 'rejected',
        ]);

        $states = $teacher->verificationChecks()->pluck('state', 'kind');

        $this->assertSame('approved', $states['government_id']);
        $this->assertSame('in_review', $states['credentials']);
        $this->assertSame('rejected', $states['teaching_demo']);
    }

    public function test_a_teacher_cannot_have_two_rows_for_the_same_check_kind(): void
    {
        $teacher = $this->makeTeacher();

        TeacherVerificationCheck::create([
            'teacher_id' => $teacher->id,
            'kind' => 'government_id',
        ]);

        $this->expectException(QueryException::class);

        TeacherVerificationCheck::create([
            'teacher_id' => $teacher->id,
            'kind' => 'government_id',
        ]);
    }

    public function test_default_state_is_pending(): void
    {
        $teacher = $this->makeTeacher();

        $check = TeacherVerificationCheck::create([
            'teacher_id' => $teacher->id,
            'kind' => 'credentials',
        ]);

        $this->assertSame('pending', $check->fresh()->state);
    }

    public function test_force_deleting_the_teacher_cascades_to_its_checks(): void
    {
        // Teacher uses SoftDeletes, so a plain delete() only sets
        // deleted_at — forceDelete() is what actually removes the row and
        // exercises the DB-level ON DELETE CASCADE.
        $teacher = $this->makeTeacher();

        TeacherVerificationCheck::create([
            'teacher_id' => $teacher->id,
            'kind' => 'government_id',
        ]);

        $teacher->forceDelete();

        $this->assertSame(0, TeacherVerificationCheck::where('teacher_id', $teacher->id)->count());
    }
}
