<?php

namespace Tests\Feature\Core;

use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\LearnerProfile;
use App\Domains\Core\Jobs\ConfirmClassEnrollmentPayment;
use App\Domains\Core\Mail\ClassEnrollmentConfirmed;
use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Models\TewtoraClass;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SeedsAuthGraph;
use Tests\Concerns\SeedsClasses;
use Tests\TestCase;

/** Families browsing, enrolling in, paying for, attending and rating Tewtora classes. */
class ClassEnrollmentHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;
    use SeedsClasses;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakePaymentService();
    }

    /** @return array{0: Account, 1: LearnerProfile, 2: string} parent, child, token */
    private function family(): array
    {
        $parent = $this->makeAccount(['email' => 'parent-'.uniqid().'@example.test']);
        $child = $this->makeLearnerProfile(['owner_account_id' => $parent->id, 'profile_type' => 'child', 'full_name' => 'Ada']);

        return [$parent, $child, $this->tokenFor($parent)];
    }

    /** forgetGuards: Sanctum caches the first request's user for the whole test otherwise. */
    private function as(string $token)
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function enroll(string $token, TewtoraClass $class, string $learnerId, string $spelling = 'enrolments')
    {
        return $this->as($token)->postJson("/api/v1/classes/{$class->public_id}/{$spelling}", ['learner_id' => $learnerId]);
    }

    private function paymentEvent(ClassEnrollment $enrollment, array $overrides = []): array
    {
        return array_merge([
            'payment_id' => $enrollment->payment_id ?? 'pay-evt-'.$enrollment->id,
            'purpose' => 'class_enrollment',
            'purpose_ref' => $enrollment->public_id,
            'amount_minor' => $enrollment->amount_minor,
            'currency_code' => $enrollment->currency_code,
            'provider_reference' => 'ref-123',
        ], $overrides);
    }

    // --- catalogue (spec §1) ---------------------------------------------------

    public function test_the_catalogue_has_every_phase_with_backend_computed_status(): void
    {
        [, , $token] = $this->family();
        $this->makeClass(['title' => 'Running'], days: 4, firstDayInDays: -8);
        $this->makeClass(['title' => 'Soon'], days: 2, firstDayInDays: 3);
        $this->makeClass(['title' => 'Over'], days: 1, firstDayInDays: -10);
        $this->makeClass(['title' => 'Draft', 'status' => TewtoraClass::STATUS_DRAFT]);
        $this->makeClass(['title' => 'Cancelled', 'status' => TewtoraClass::STATUS_CANCELLED]);

        $all = $this->as($token)->getJson('/api/v1/classes')->assertOk()->json('data');
        $this->assertSame(['Running', 'Soon', 'Over'], array_column($all, 'title'), 'drafts/cancelled hidden; finished classes last');
        $this->assertSame(['active', 'upcoming', 'completed'], array_column($all, 'status'));

        $this->as($token)->getJson('/api/v1/classes?status=active')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Running');
        $this->as($token)->getJson('/api/v1/classes?status=upcoming')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Soon');
        $this->as($token)->getJson('/api/v1/classes?status=completed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Over');
    }

    public function test_a_catalogue_item_has_the_fields_the_class_card_needs(): void
    {
        [, $child, $token] = $this->family();
        $subject = $this->makeSubject(['code' => 'mathematics', 'display_name' => 'Mathematics']);
        $curriculum = $this->makeCurriculum(['code' => 'nigerian', 'display_name' => 'Nigerian']);
        $class = $this->makeClass(['subject_id' => $subject->id, 'curriculum_id' => $curriculum->id, 'level' => 'JSS 2', 'art' => 'maths', 'capacity' => 5, 'rating_avg' => 4.8, 'rating_count' => 3], days: 4, firstDayInDays: -15);
        $this->makeEnrollment($class, ['learner_profile_id' => $child->id]);

        $item = $this->as($token)->getJson('/api/v1/classes')->assertOk()->json('data.0');

        $this->assertSame('Mathematics', $item['subject']);
        $this->assertSame('nigerian', $item['curriculum']);
        $this->assertSame('JSS 2', $item['level']);
        $this->assertSame('small_group', $item['format']);
        $this->assertSame('maths', $item['art']);
        $this->assertEquals(4.8, $item['rating_avg']);
        $this->assertSame('16:00', $item['starts_at_time']);
        $this->assertSame('17:00', $item['ends_at_time']);
        $this->assertSame(4, $item['total_days']);
        $this->assertSame(3, $item['days_done']);
        $this->assertSame(4, $item['next_day']['day']);
        $this->assertSame(4, $item['seats_left']);
        $this->assertSame(2500000, $item['price_minor']);
        $this->assertSame([$child->public_id], $item['enrolled_learner_ids']);
        $this->assertSame([CarbonImmutable::now('Africa/Lagos')->subDays(15)->dayOfWeekIso], $item['days']);
        $this->assertArrayNotHasKey('sessions', $item);
        $this->assertArrayNotHasKey('meeting_link', $item);
    }

    public function test_drafts_404_and_cancelled_classes_only_show_to_families_who_were_in_them(): void
    {
        [, $child, $token] = $this->family();
        [, , $strangerToken] = $this->family();
        $draft = $this->makeClass(['status' => TewtoraClass::STATUS_DRAFT]);
        $cancelled = $this->makeClass(['status' => TewtoraClass::STATUS_CANCELLED, 'cancellation_reason' => 'Teacher unwell']);
        $this->makeEnrollment($cancelled, ['learner_profile_id' => $child->id, 'status' => ClassEnrollment::STATUS_CANCELLED, 'confirmed_at' => null]);

        $this->as($token)->getJson("/api/v1/classes/{$draft->public_id}")->assertNotFound();
        $this->as($token)->getJson("/api/v1/classes/{$cancelled->public_id}")->assertOk()->assertJsonPath('data.cancellation_reason', 'Teacher unwell');
        $this->as($strangerToken)->getJson("/api/v1/classes/{$cancelled->public_id}")->assertNotFound();
    }

    // --- class days and meeting links (spec §2) ---------------------------------

    public function test_class_days_are_numbered_with_status_and_lagos_times(): void
    {
        [, , $token] = $this->family();
        $class = $this->makeClass([], days: 3, firstDayInDays: -10); // days at -10, -3 and +4

        $days = $this->as($token)->getJson("/api/v1/classes/{$class->public_id}/sessions")->assertOk()->json('data');

        $this->assertSame([1, 2, 3], array_column($days, 'day'));
        $this->assertSame(['completed', 'completed', 'upcoming'], array_column($days, 'status'));
        $this->assertStringEndsWith('T16:00:00+01:00', $days[0]['starts_at']);
        $this->assertNull($days[1]['meeting_url'], 'not enrolled: never a link');
    }

    /** The link is the thing being paid for: enrolled families get it only shortly before each day. */
    public function test_an_enrolled_family_sees_a_days_link_only_inside_its_reveal_window(): void
    {
        [$parent, $child, $token] = $this->family();
        $class = $this->makeClass([], days: 1, firstDayInDays: 1);
        $this->makeEnrollment($class, ['learner_profile_id' => $child->id, 'payer_account_id' => $parent->id]);
        $start = $class->starts_at;

        $this->as($token)->getJson("/api/v1/classes/{$class->public_id}/sessions")->assertJsonPath('data.0.meeting_url', null);

        $this->travelTo($start->copy()->subMinutes(30));
        $this->as($token)->getJson("/api/v1/classes/{$class->public_id}/sessions")->assertJsonPath('data.0.meeting_url', 'https://meet.example.test/algebra');
        $this->as($token)->getJson("/api/v1/classes/{$class->public_id}")->assertJsonPath('data.sessions.0.meeting_url', 'https://meet.example.test/algebra');

        $this->travelTo($start->copy()->addHours(2));
        $this->as($token)->getJson("/api/v1/classes/{$class->public_id}/sessions")->assertJsonPath('data.0.meeting_url', null);
    }

    public function test_a_days_own_link_overrides_the_class_link_and_the_child_login_sees_it_too(): void
    {
        [$parent, $child] = $this->family();
        $childLogin = $this->makeAccount(['account_type' => 'child', 'email' => null, 'username' => 'kid'.uniqid()]);
        $child->update(['linked_login_account_id' => $childLogin->id]);
        $class = $this->makeClass([], days: 1, firstDayInDays: 1);
        $class->sessions()->first()->update(['meeting_link' => 'https://zoom.example.test/day1']);
        $this->makeEnrollment($class, ['learner_profile_id' => $child->id, 'payer_account_id' => $parent->id]);

        $this->travelTo($class->starts_at->copy()->subMinutes(5));
        $this->as($this->tokenFor($childLogin))->getJson("/api/v1/classes/{$class->public_id}/sessions")
            ->assertJsonPath('data.0.meeting_url', 'https://zoom.example.test/day1');
    }

    public function test_the_teacher_and_admins_always_see_links_other_teachers_never(): void
    {
        $teacher = $this->makeTeacher(['verification_status' => 'approved']);
        $class = $this->makeClass(['teacher_id' => $teacher->id, 'teacher_account_id' => $teacher->account_id], days: 1, firstDayInDays: 5);
        [, $child] = $this->family();
        $this->makeEnrollment($class, ['learner_profile_id' => $child->id]);

        $this->as($this->tokenFor($teacher->account))->getJson("/api/v1/classes/{$class->public_id}/sessions")
            ->assertJsonPath('data.0.meeting_url', 'https://meet.example.test/algebra');
        $this->as($this->tokenFor($teacher->account))->getJson("/api/v1/classes/{$class->public_id}/roster")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.learner.name', 'Ada');

        $other = $this->tokenFor($this->makeTeacher(['verification_status' => 'approved'])->account);
        $this->as($other)->getJson("/api/v1/classes/{$class->public_id}/sessions")->assertJsonPath('data.0.meeting_url', null);
        $this->as($other)->getJson("/api/v1/classes/{$class->public_id}/roster")->assertForbidden();
    }

    public function test_the_week_schedule_lists_days_across_classes_in_a_date_range(): void
    {
        [, , $token] = $this->family();
        $subject = $this->makeSubject(['code' => 'english', 'display_name' => 'English Language']);
        $a = $this->makeClass(['title' => 'A', 'subject_id' => $subject->id], days: 2, firstDayInDays: 1);
        $this->makeClass(['title' => 'B'], days: 1, firstDayInDays: 2);
        $this->makeClass(['title' => 'Far'], days: 1, firstDayInDays: 30);
        $this->makeClass(['title' => 'Hidden draft', 'status' => TewtoraClass::STATUS_DRAFT], days: 1, firstDayInDays: 2);

        $today = CarbonImmutable::now('Africa/Lagos');
        $rows = $this->as($token)->getJson('/api/v1/classes/sessions?from='.$today->toDateString().'&to='.$today->addDays(6)->toDateString())
            ->assertOk()->json('data');

        $this->assertSame(['A', 'B'], array_column(array_column($rows, 'class'), 'title'));
        $this->assertSame('English Language', $rows[0]['class']['subject']);
        $this->assertSame(1, $rows[0]['day']);
        $this->assertSame(2, $rows[0]['class']['total_days']);
        $this->assertSame($a->public_id, $rows[0]['class']['id']);

        $this->as($token)->getJson('/api/v1/classes/sessions?from=2026-01-01&to=2026-06-01')->assertStatus(422);
    }

    // --- enrolling and paying (spec §3) -----------------------------------------

    public function test_enrolling_in_a_paid_class_holds_a_seat_and_returns_a_paystack_checkout(): void
    {
        [$parent, $child, $token] = $this->family();
        $class = $this->makeClass(['capacity' => 1]);

        $response = $this->enroll($token, $class, $child->public_id)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.checkout_url', 'https://checkout.paystack.test/abc123')
            ->assertJsonPath('data.learner.name', 'Ada')
            ->assertJsonPath('data.amount_minor', 2500000);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://payment.test/api/v1/internal/checkouts'
            && $request->hasHeader('X-Internal-Token', 'internal-secret')
            && $request['purpose_ref'] === $response->json('data.id')
            && $request['amount_minor'] === 2500000
            && $request['email'] === $parent->email);

        [, $other, $otherToken] = $this->family();
        $this->enroll($otherToken, $class, $other->public_id)->assertStatus(409)->assertJsonPath('error.code', 'class_full');
    }

    public function test_both_spellings_work_and_a_retry_reuses_the_same_seat_and_checkout(): void
    {
        [, $child, $token] = $this->family();
        $class = $this->makeClass();

        $first = $this->enroll($token, $class, $child->public_id, 'enrolments')->assertCreated()->json('data.id');
        $this->enroll($token, $class, $child->public_id, 'enrollments')->assertOk()->assertJsonPath('data.id', $first);

        Http::assertSentCount(1);
    }

    public function test_an_uncapped_class_never_fills_up(): void
    {
        $class = $this->makeClass(['capacity' => null]);

        foreach (range(1, 3) as $i) {
            [, $child, $token] = $this->family();
            $this->enroll($token, $class, $child->public_id)->assertCreated();
        }
    }

    /** The frontend shows "Enrol" on active classes too: you can join a course that's already running. */
    public function test_a_running_class_takes_late_enrolments_a_finished_one_does_not(): void
    {
        [, $child, $token] = $this->family();

        $this->enroll($token, $this->makeClass([], days: 4, firstDayInDays: -8), $child->public_id)->assertCreated();
        $this->enroll($token, $this->makeClass([], days: 1, firstDayInDays: -8), $child->public_id)
            ->assertStatus(409)->assertJsonPath('error.code', 'class_not_open');
    }

    public function test_an_expired_hold_gives_the_seat_back(): void
    {
        [, $child, $token] = $this->family();
        $class = $this->makeClass(['capacity' => 1]);
        $stale = $this->makeEnrollment($class, ['status' => ClassEnrollment::STATUS_PENDING, 'confirmed_at' => null, 'hold_expires_at' => now()->subMinute()]);

        $this->enroll($token, $class, $child->public_id)->assertCreated();
        $this->assertSame(ClassEnrollment::STATUS_EXPIRED, $stale->fresh()->status);

        $this->artisan('classes:expire-holds')->assertSuccessful();
    }

    public function test_if_checkout_cannot_start_the_seat_is_released(): void
    {
        [, $child, $token] = $this->family();
        $class = $this->makeClass(['capacity' => 1]);
        $this->failPaymentService();

        $this->enroll($token, $class, $child->public_id)->assertStatus(503)->assertJsonPath('error.code', 'payment_unavailable');
        $this->assertSame(ClassEnrollment::STATUS_EXPIRED, ClassEnrollment::sole()->status);

        $this->fakePaymentService();
        $this->enroll($token, $class, $child->public_id)->assertCreated();
    }

    public function test_a_free_class_confirms_immediately_with_no_checkout(): void
    {
        [$parent, $child, $token] = $this->family();
        $class = $this->makeClass(['price_minor' => 0]);

        $this->enroll($token, $class, $child->public_id)
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.is_free', true)
            ->assertJsonMissingPath('data.checkout_url');

        Http::assertNothingSent();
        Mail::assertQueued(ClassEnrollmentConfirmed::class, fn ($mail) => $mail->hasTo($parent->email));
        $this->enroll($token, $class, $child->public_id)->assertStatus(409)->assertJsonPath('error.code', 'already_enrolled');
        $this->as($token)->getJson('/api/v1/classes')->assertJsonPath('data.0.enrolled_learner_ids', [$child->public_id]);
    }

    public function test_only_the_learners_owner_can_enrol_and_pay(): void
    {
        $class = $this->makeClass();
        [, $child] = $this->family();

        [, , $strangerToken] = $this->family();
        $this->enroll($strangerToken, $class, $child->public_id)->assertForbidden();

        $childLogin = $this->makeAccount(['account_type' => 'child', 'email' => null, 'username' => 'kid'.uniqid()]);
        $child->update(['linked_login_account_id' => $childLogin->id]);
        $this->enroll($this->tokenFor($childLogin), $class, $child->public_id)->assertForbidden();

        $this->assertSame(0, ClassEnrollment::count());
    }

    public function test_an_independent_student_enrols_themselves(): void
    {
        $student = $this->makeAccount(['account_type' => 'independent_student']);
        $self = $this->makeLearnerProfile(['owner_account_id' => $student->id, 'profile_type' => 'own']);

        $this->enroll($this->tokenFor($student), $this->makeClass(), $self->public_id)->assertCreated();
    }

    // --- confirming payment -----------------------------------------------------

    public function test_a_payment_event_confirms_the_seat_and_emails_a_receipt_once(): void
    {
        [$parent, $child, $token] = $this->family();
        $class = $this->makeClass();
        $id = $this->enroll($token, $class, $child->public_id)->json('data.id');
        $enrollment = ClassEnrollment::where('public_id', $id)->sole();

        ConfirmClassEnrollmentPayment::dispatchSync($this->paymentEvent($enrollment));
        ConfirmClassEnrollmentPayment::dispatchSync($this->paymentEvent($enrollment));

        $this->as($token)->getJson("/api/v1/class-enrollments/{$id}")->assertOk()->assertJsonPath('data.status', 'confirmed');
        Mail::assertQueued(ClassEnrollmentConfirmed::class, fn ($mail) => $mail->hasTo($parent->email));
        Mail::assertQueuedCount(1);
    }

    public function test_a_payment_for_the_wrong_amount_never_confirms(): void
    {
        [, $child, $token] = $this->family();
        $id = $this->enroll($token, $this->makeClass(), $child->public_id)->json('data.id');
        $enrollment = ClassEnrollment::where('public_id', $id)->sole();

        ConfirmClassEnrollmentPayment::dispatchSync($this->paymentEvent($enrollment, ['amount_minor' => 100]));

        $this->assertSame(ClassEnrollment::STATUS_PENDING, $enrollment->fresh()->status);
    }

    public function test_a_late_payment_still_confirms_and_one_after_cancellation_is_only_recorded(): void
    {
        $class = $this->makeClass();
        $late = $this->makeEnrollment($class, ['status' => ClassEnrollment::STATUS_EXPIRED, 'confirmed_at' => null, 'expired_at' => now()]);
        ConfirmClassEnrollmentPayment::dispatchSync($this->paymentEvent($late));
        $this->assertSame(ClassEnrollment::STATUS_CONFIRMED, $late->fresh()->status);

        $cancelledClass = $this->makeClass(['status' => TewtoraClass::STATUS_CANCELLED]);
        $cancelled = $this->makeEnrollment($cancelledClass, ['status' => ClassEnrollment::STATUS_CANCELLED, 'confirmed_at' => null]);
        ConfirmClassEnrollmentPayment::dispatchSync($this->paymentEvent($cancelled));
        $this->assertSame(ClassEnrollment::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertNotNull($cancelled->fresh()->paid_at);
    }

    public function test_verify_payment_confirms_straight_away_and_only_the_payer_may_call_it(): void
    {
        [, $child, $token] = $this->family();
        $id = $this->enroll($token, $this->makeClass(), $child->public_id)->json('data.id');
        $paymentId = ClassEnrollment::where('public_id', $id)->value('payment_id');

        [, , $strangerToken] = $this->family();
        $this->as($strangerToken)->postJson("/api/v1/class-enrollments/{$id}/verify-payment")->assertNotFound();

        $this->fakePaymentService(status: ['payment_id' => $paymentId, 'status' => 'processing']);
        $this->as($token)->postJson("/api/v1/class-enrollments/{$id}/verify-payment")->assertOk()->assertJsonPath('data.status', 'pending_payment');

        $this->fakePaymentService(status: ['payment_id' => $paymentId]);
        $this->as($token)->postJson("/api/v1/class-enrollments/{$id}/verify-payment")->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_a_child_login_sees_their_enrolments(): void
    {
        [$parent, $child] = $this->family();
        $childLogin = $this->makeAccount(['account_type' => 'child', 'email' => null, 'username' => 'kid'.uniqid()]);
        $child->update(['linked_login_account_id' => $childLogin->id]);
        $this->makeEnrollment($this->makeClass(), ['learner_profile_id' => $child->id, 'payer_account_id' => $parent->id]);

        $this->as($this->tokenFor($childLogin))->getJson('/api/v1/class-enrollments')->assertOk()->assertJsonCount(1, 'data');
    }

    // --- ratings ----------------------------------------------------------------

    public function test_a_paying_family_rates_once_a_day_has_finished_and_the_average_updates(): void
    {
        [$parent, $child, $token] = $this->family();
        $class = $this->makeClass([], days: 3, firstDayInDays: -7);
        $this->makeEnrollment($class, ['learner_profile_id' => $child->id, 'payer_account_id' => $parent->id]);
        [$other, $otherChild, $otherToken] = $this->family();
        $this->makeEnrollment($class, ['learner_profile_id' => $otherChild->id, 'payer_account_id' => $other->id]);

        $this->as($token)->postJson("/api/v1/classes/{$class->public_id}/rating", ['rating' => 5])->assertOk();
        $this->as($otherToken)->postJson("/api/v1/classes/{$class->public_id}/rating", ['rating' => 4, 'comment' => 'Great'])
            ->assertOk()->assertJsonPath('data.rating_count', 2);
        $this->as($token)->postJson("/api/v1/classes/{$class->public_id}/rating", ['rating' => 3])->assertOk(); // replaces, not adds

        $this->as($token)->getJson("/api/v1/classes/{$class->public_id}")
            ->assertJsonPath('data.rating_avg', 3.5)
            ->assertJsonPath('data.rating_count', 2);
    }

    public function test_rating_needs_a_confirmed_seat_and_a_finished_day(): void
    {
        [$parent, $child, $token] = $this->family();
        $notStarted = $this->makeClass();
        $this->makeEnrollment($notStarted, ['learner_profile_id' => $child->id, 'payer_account_id' => $parent->id]);

        $this->as($token)->postJson("/api/v1/classes/{$notStarted->public_id}/rating", ['rating' => 5])
            ->assertStatus(409)->assertJsonPath('error.code', 'class_not_rateable_yet');

        [, , $strangerToken] = $this->family();
        $this->as($strangerToken)->postJson("/api/v1/classes/{$this->makeClass([], days: 2, firstDayInDays: -7)->public_id}/rating", ['rating' => 5])
            ->assertForbidden();
        $this->as($token)->postJson("/api/v1/classes/{$notStarted->public_id}/rating", ['rating' => 6])->assertStatus(422);
    }
}
