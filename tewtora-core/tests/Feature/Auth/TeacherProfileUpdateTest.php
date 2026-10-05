<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers docs/needed-endpoints-teacher-onboarding.md §3: a teacher writing
 * their own profile via PATCH /teachers/:id (subjects/curricula/levels/
 * format/rate/years/availability/bio), additive to GET /teachers/:id.
 */
class TeacherProfileUpdateTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    private function validBio(): string
    {
        return 'I have been tutoring secondary school mathematics for several years.';
    }

    public function test_a_teacher_can_update_their_own_profile(): void
    {
        $teacher = $this->makeTeacher();
        $token = $this->tokenFor($teacher->account);
        $this->makeSubject(['code' => 'mathematics']);
        $this->makeCurriculum(['code' => 'british']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/teachers/{$teacher->public_id}", [
                'name' => 'Chinedu Okafor',
                'subjects' => ['mathematics'],
                'curricula' => ['british'],
                'levels' => ['year_10_11'],
                'format' => 'both',
                'price_per_session_minor' => 1400000,
                'years_teaching' => 6,
                'availability' => [
                    ['day' => 'thu', 'starts_at' => '16:00', 'ends_at' => '20:00'],
                ],
                'about' => $this->validBio(),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Chinedu Okafor')
            ->assertJsonPath('data.subjects', ['mathematics'])
            ->assertJsonPath('data.curricula', ['british'])
            ->assertJsonPath('data.levels', ['year_10_11'])
            ->assertJsonPath('data.format', 'both')
            ->assertJsonPath('data.price_per_session_minor', 1400000)
            ->assertJsonPath('data.years_teaching', 6)
            ->assertJsonPath('data.about', $this->validBio())
            ->assertJsonPath('data.availability.0.day', 'thu')
            ->assertJsonPath('data.availability.0.starts_at', '16:00')
            ->assertJsonPath('data.availability.0.ends_at', '20:00');
    }

    public function test_updating_availability_replaces_the_previous_set(): void
    {
        $teacher = $this->makeTeacher();
        $token = $this->tokenFor($teacher->account);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/teachers/{$teacher->public_id}", [
                'availability' => [
                    ['day' => 'mon', 'starts_at' => '09:00', 'ends_at' => '11:00'],
                ],
            ])->assertOk();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/teachers/{$teacher->public_id}", [
                'availability' => [
                    ['day' => 'fri', 'starts_at' => '13:00', 'ends_at' => '15:00'],
                ],
            ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.availability'));
        $response->assertJsonPath('data.availability.0.day', 'fri');
    }

    public function test_a_partial_update_does_not_touch_other_fields(): void
    {
        $teacher = $this->makeTeacher(['years_experience' => 3, 'rate_minor' => 500000]);
        $token = $this->tokenFor($teacher->account);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/teachers/{$teacher->public_id}", [
                'years_teaching' => 9,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.years_teaching', 9)
            ->assertJsonPath('data.price_per_session_minor', 500000);
    }

    public function test_a_teacher_cannot_update_someone_elses_profile(): void
    {
        $teacher = $this->makeTeacher();
        $stranger = $this->makeTeacher();
        $token = $this->tokenFor($stranger->account);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/teachers/{$teacher->public_id}", [
                'years_teaching' => 9,
            ]);

        $response->assertForbidden();
    }

    public function test_a_parent_cannot_update_a_teacher_profile(): void
    {
        $teacher = $this->makeTeacher();
        $parent = $this->makeAccount();
        $token = $this->tokenFor($parent);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/teachers/{$teacher->public_id}", [
                'years_teaching' => 9,
            ]);

        $response->assertForbidden();
    }

    public function test_an_invalid_level_is_rejected(): void
    {
        $teacher = $this->makeTeacher();
        $token = $this->tokenFor($teacher->account);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/teachers/{$teacher->public_id}", [
                'levels' => ['not-a-real-level'],
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('levels.0', $response->json('error.fields'));
    }

    public function test_a_name_containing_contact_info_is_rejected(): void
    {
        $teacher = $this->makeTeacher();
        $token = $this->tokenFor($teacher->account);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/teachers/{$teacher->public_id}", [
                'name' => 'Call me on 08012345678',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('name', $response->json('error.fields'));
    }

    public function test_updating_a_profile_does_not_change_verification_status(): void
    {
        $teacher = $this->makeTeacher(['verification_status' => 'approved']);
        $token = $this->tokenFor($teacher->account);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/teachers/{$teacher->public_id}", [
                'years_teaching' => 10,
            ])->assertOk();

        $this->assertSame('approved', $teacher->fresh()->verification_status);
    }
}
