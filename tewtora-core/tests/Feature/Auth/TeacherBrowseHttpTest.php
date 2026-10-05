<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers docs/needed-endpoints-browse-matching.md §1: an unranked, filtered
 * list of real verified teachers — not the ranked /matches §2 asks for
 * eventually.
 */
class TeacherBrowseHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    public function test_an_unverified_teacher_is_excluded_from_the_browse_list(): void
    {
        $this->makeTeacher(['verification_status' => 'pending']);
        $parent = $this->makeAccount();

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->getJson('/api/v1/teachers');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_teacher_suspended_from_new_matches_is_excluded(): void
    {
        $this->makeTeacher(['verification_status' => 'approved', 'new_matches_suspended_at' => now()]);
        $parent = $this->makeAccount();

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->getJson('/api/v1/teachers');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_verified_teacher_appears_with_their_name(): void
    {
        $this->makeTeacher(['verification_status' => 'approved', 'full_name' => 'Chinedu Okafor']);
        $parent = $this->makeAccount();

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->getJson('/api/v1/teachers');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Chinedu Okafor');
    }

    public function test_filtering_by_subject_excludes_a_teacher_who_doesnt_teach_it(): void
    {
        $mathsSubject = $this->makeSubject(['code' => 'mathematics']);
        $physicsSubject = $this->makeSubject(['code' => 'physics']);
        $mathsTeacher = $this->makeTeacher(['verification_status' => 'approved']);
        $mathsTeacher->subjects()->attach($mathsSubject->id);
        $physicsTeacher = $this->makeTeacher(['verification_status' => 'approved']);
        $physicsTeacher->subjects()->attach($physicsSubject->id);
        $parent = $this->makeAccount();

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->getJson('/api/v1/teachers?subject=mathematics');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mathsTeacher->public_id);
    }

    public function test_filtering_by_format_includes_a_teacher_offering_both(): void
    {
        $this->makeTeacher(['verification_status' => 'approved', 'preferred_format' => 'both']);
        $this->makeTeacher(['verification_status' => 'approved', 'preferred_format' => 'one_on_one']);
        $parent = $this->makeAccount();

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($parent)}")
            ->getJson('/api/v1/teachers?format=group');

        $response->assertOk()->assertJsonCount(1, 'data');
    }
}
