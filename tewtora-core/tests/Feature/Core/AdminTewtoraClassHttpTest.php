<?php

namespace Tests\Feature\Core;

use App\Domains\Core\Mail\ClassCancelled;
use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Models\TewtoraClass;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsAuthGraph;
use Tests\Concerns\SeedsClasses;
use Tests\TestCase;

/** /internal/classes — admins create, edit, publish and cancel Tewtora classes. */
class AdminTewtoraClassHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;
    use SeedsClasses;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $this->token = $this->tokenFor($this->makeAccount(['account_type' => 'admin']));
    }

    private function admin()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    private function nextMonday(): string
    {
        return CarbonImmutable::now('Africa/Lagos')->next('Monday')->toDateString();
    }

    public function test_only_admins_can_reach_class_management(): void
    {
        $parent = $this->tokenFor($this->makeAccount());

        $this->withHeader('Authorization', "Bearer {$parent}")->getJson('/api/v1/internal/classes')->assertForbidden();
        $this->withHeader('Authorization', "Bearer {$parent}")->postJson('/api/v1/internal/classes', [])->assertForbidden();
    }

    /** The frontend's way of describing a class: "Tuesdays & Thursdays, 16:00–17:00, 12 days". */
    public function test_a_weekly_pattern_generates_numbered_class_days_in_lagos_time(): void
    {
        $this->makeSubject(['code' => 'mathematics', 'display_name' => 'Mathematics']);
        $this->makeCurriculum(['code' => 'nigerian', 'display_name' => 'Nigerian']);
        $teacher = $this->makeTeacher(['verification_status' => 'approved', 'full_name' => 'Amaka Obi']);

        $response = $this->admin()->postJson('/api/v1/internal/classes', [
            'title' => 'Algebra Foundations',
            'description' => 'Expressions, simple equations and word problems.',
            'subject' => 'mathematics',
            'curriculum' => 'nigerian',
            'level' => 'JSS 2',
            'format' => 'small_group',
            'art' => 'maths',
            'teacher_id' => $teacher->public_id,
            'price_minor' => 2500000,
            'capacity' => 12,
            'meeting_link' => 'https://meet.example.test/algebra',
            'schedule' => ['starts_on' => $this->nextMonday(), 'days' => [2, 4], 'start_time' => '16:00', 'end_time' => '17:00', 'total_days' => 12],
        ])->assertCreated();

        $response->assertJsonPath('data.lifecycle', 'draft')
            ->assertJsonPath('data.status', 'upcoming')
            ->assertJsonPath('data.subject', 'Mathematics')
            ->assertJsonPath('data.curriculum', 'nigerian')
            ->assertJsonPath('data.curriculum_name', 'Nigerian')
            ->assertJsonPath('data.level', 'JSS 2')
            ->assertJsonPath('data.format', 'small_group')
            ->assertJsonPath('data.art', 'maths')
            ->assertJsonPath('data.teacher.name', 'Amaka Obi')
            ->assertJsonPath('data.days', [2, 4])
            ->assertJsonPath('data.starts_at_time', '16:00')
            ->assertJsonPath('data.ends_at_time', '17:00')
            ->assertJsonPath('data.total_days', 12)
            ->assertJsonPath('data.seats_left', 12)
            ->assertJsonPath('data.sessions.0.day', 1)
            ->assertJsonPath('data.sessions.11.day', 12);

        $first = $response->json('data.sessions.0');
        $this->assertSame(2, CarbonImmutable::parse($first['starts_at'])->dayOfWeekIso, 'Day 1 is the first Tuesday');
        $this->assertStringEndsWith('T16:00:00+01:00', $first['starts_at']);
        $this->assertSame($response->json('data.starts_on'), $first['date']);
    }

    public function test_classes_are_free_and_uncapped_unless_told_otherwise(): void
    {
        $this->admin()->postJson('/api/v1/internal/classes', ['title' => 'Open Day Taster'])
            ->assertCreated()
            ->assertJsonPath('data.price_minor', 0)
            ->assertJsonPath('data.is_free', true)
            ->assertJsonPath('data.capacity', null)
            ->assertJsonPath('data.seats_left', null)
            ->assertJsonPath('data.currency_code', 'NGN')
            ->assertJsonPath('data.format', 'small_group');
    }

    public function test_an_image_can_come_with_the_class_and_is_stored_on_the_local_disk(): void
    {
        $response = $this->admin()->post('/api/v1/internal/classes', [
            'title' => 'With a cover',
            'image' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
        ], ['Accept' => 'application/json'])->assertCreated();

        $class = TewtoraClass::where('public_id', $response->json('data.id'))->sole();
        Storage::disk('public')->assertExists($class->image_path);
        $this->assertStringContainsString($class->image_path, $response->json('data.image_url'));
    }

    /** Links can be posted per day, closer to the time, so publishing doesn't need one. */
    public function test_publishing_needs_a_future_class_day_but_not_a_link(): void
    {
        $id = $this->admin()->postJson('/api/v1/internal/classes', ['title' => 'Not Ready'])->json('data.id');

        $this->admin()->postJson("/api/v1/internal/classes/{$id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'class_not_publishable');

        $this->admin()->postJson('/api/v1/internal/classes', [
            'title' => 'Ready',
            'schedule' => ['starts_on' => $this->nextMonday(), 'days' => [6], 'start_time' => '10:00', 'end_time' => '11:30', 'total_days' => 8],
            'publish' => true,
        ])->assertCreated()->assertJsonPath('data.lifecycle', 'published')->assertJsonPath('data.meeting_link', null);
    }

    public function test_bad_input_is_rejected(): void
    {
        $this->admin()->postJson('/api/v1/internal/classes', ['title' => 'Bad', 'subject' => 'nope'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['subject']]]);
        $this->admin()->postJson('/api/v1/internal/classes', ['title' => 'Bad', 'curriculum' => 'nope'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['curriculum']]]);
        $this->admin()->postJson('/api/v1/internal/classes', ['title' => 'Bad', 'teacher_id' => $this->makeTeacher(['verification_status' => 'pending'])->public_id])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['teacher_id']]]);
        $this->admin()->postJson('/api/v1/internal/classes', ['title' => 'Bad', 'format' => 'one_on_one', 'art' => 'history'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['format', 'art']]]);
        $this->admin()->postJson('/api/v1/internal/classes', ['title' => 'Bad', 'meeting_link' => 'http://insecure.example.test'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['meeting_link']]]);
        $this->admin()->postJson('/api/v1/internal/classes', [
            'title' => 'Both',
            'schedule' => ['starts_on' => $this->nextMonday(), 'days' => [1], 'start_time' => '10:00', 'end_time' => '11:00', 'total_days' => 1],
            'sessions' => [['starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHour()->toIso8601String()]],
        ])->assertStatus(422);
        $this->admin()->postJson('/api/v1/internal/classes', [
            'title' => 'Backwards',
            'schedule' => ['starts_on' => $this->nextMonday(), 'days' => [1], 'start_time' => '11:00', 'end_time' => '10:00', 'total_days' => 1],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['schedule.end_time']]]);
    }

    public function test_explicit_days_are_numbered_by_date_whatever_order_they_arrive_in(): void
    {
        $later = now()->addWeeks(2)->toIso8601String();
        $sooner = now()->addWeek()->toIso8601String();

        $this->admin()->postJson('/api/v1/internal/classes', [
            'title' => 'Out of order',
            'sessions' => [
                ['starts_at' => $later, 'ends_at' => now()->addWeeks(2)->addHour()->toIso8601String()],
                ['starts_at' => $sooner, 'ends_at' => now()->addWeek()->addHour()->toIso8601String(), 'meeting_link' => 'https://meet.example.test/day-one'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.sessions.0.day', 1)
            ->assertJsonPath('data.sessions.0.meeting_url', 'https://meet.example.test/day-one');
    }

    public function test_an_admin_posts_one_days_link_and_moving_a_day_renumbers(): void
    {
        $class = $this->makeClass([], days: 3);

        $this->admin()->patchJson("/api/v1/internal/classes/{$class->public_id}/sessions/2", ['meeting_link' => 'https://zoom.example.test/day2'])
            ->assertOk()->assertJsonPath('data.sessions.1.meeting_url', 'https://zoom.example.test/day2');

        // Move day 1 after day 3: it becomes day 3, and the old days 2 and 3 become 1 and 2.
        $this->admin()->patchJson("/api/v1/internal/classes/{$class->public_id}/sessions/1", [
            'starts_at' => now()->addWeeks(5)->toIso8601String(),
            'ends_at' => now()->addWeeks(5)->addHour()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.sessions.0.meeting_url', 'https://zoom.example.test/day2');

        $this->assertTrue($class->fresh()->ends_at->gt(now()->addWeeks(4)));
        $this->admin()->patchJson("/api/v1/internal/classes/{$class->public_id}/sessions/9", ['meeting_link' => null])->assertNotFound();
    }

    public function test_the_price_is_frozen_once_anyone_has_enrolled(): void
    {
        $class = $this->makeClass();
        $this->makeEnrollment($class);

        $this->admin()->patchJson("/api/v1/internal/classes/{$class->public_id}", ['price_minor' => 100])
            ->assertStatus(409)->assertJsonPath('error.code', 'class_fee_locked');
        $this->admin()->patchJson("/api/v1/internal/classes/{$class->public_id}", ['price_minor' => $class->price_minor, 'title' => 'Same price'])
            ->assertOk();
    }

    public function test_capacity_cannot_drop_below_taken_seats_but_can_be_removed(): void
    {
        $class = $this->makeClass(['capacity' => 5]);
        $this->makeEnrollment($class);
        $this->makeEnrollment($class);

        $this->admin()->patchJson("/api/v1/internal/classes/{$class->public_id}", ['capacity' => 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'capacity_below_enrollments');
        $this->admin()->patchJson("/api/v1/internal/classes/{$class->public_id}", ['capacity' => null])
            ->assertOk()->assertJsonPath('data.seats_left', null);
    }

    public function test_cancelling_cancels_every_seat_and_emails_confirmed_families_only(): void
    {
        $class = $this->makeClass();
        $payer = $this->makeAccount();
        $confirmed = $this->makeEnrollment($class, ['payer_account_id' => $payer->id, 'paid_at' => now()]);
        $pending = $this->makeEnrollment($class, ['status' => ClassEnrollment::STATUS_PENDING, 'confirmed_at' => null, 'hold_expires_at' => now()->addMinutes(20)]);

        $this->admin()->postJson("/api/v1/internal/classes/{$class->public_id}/cancel", ['reason' => 'Teacher unavailable'])
            ->assertOk()
            ->assertJsonPath('data.lifecycle', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Teacher unavailable');

        $this->assertSame(ClassEnrollment::STATUS_CANCELLED, $confirmed->fresh()->status);
        $this->assertSame(ClassEnrollment::STATUS_CANCELLED, $pending->fresh()->status);
        Mail::assertQueued(ClassCancelled::class, fn (ClassCancelled $mail) => $mail->hasTo($payer->email));
        Mail::assertQueuedCount(1);

        $this->admin()->patchJson("/api/v1/internal/classes/{$class->public_id}", ['title' => 'Too late'])
            ->assertStatus(409)->assertJsonPath('error.code', 'class_not_editable');
    }

    public function test_replacing_the_image_deletes_the_old_file(): void
    {
        $class = $this->makeClass();

        $this->admin()->post("/api/v1/internal/classes/{$class->public_id}/image", ['image' => UploadedFile::fake()->image('a.png', 800, 400)], ['Accept' => 'application/json'])->assertOk();
        $oldPath = $class->fresh()->image_path;

        $this->admin()->post("/api/v1/internal/classes/{$class->public_id}/image", ['image' => UploadedFile::fake()->image('b.webp', 800, 400)], ['Accept' => 'application/json'])->assertOk();

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($class->fresh()->image_path);

        $this->admin()->deleteJson("/api/v1/internal/classes/{$class->public_id}/image")->assertOk()->assertJsonPath('data.image_url', null);
    }

    public function test_non_images_and_tiny_images_are_rejected(): void
    {
        $class = $this->makeClass();

        $this->admin()->post("/api/v1/internal/classes/{$class->public_id}/image", ['image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->admin()->post("/api/v1/internal/classes/{$class->public_id}/image", ['image' => UploadedFile::fake()->image('tiny.jpg', 50, 50)], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_the_admin_list_filters_by_lifecycle(): void
    {
        $this->makeClass(['title' => 'Live']);
        $this->makeClass(['title' => 'Draft one', 'status' => TewtoraClass::STATUS_DRAFT]);

        $this->admin()->getJson('/api/v1/internal/classes?status=draft')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Draft one');
    }

    public function test_finished_classes_are_marked_completed(): void
    {
        $class = $this->makeClass([], days: 1, firstDayInDays: -3);

        $this->artisan('classes:complete-finished')->assertSuccessful();

        $this->assertSame(TewtoraClass::STATUS_COMPLETED, $class->fresh()->status);
    }
}
