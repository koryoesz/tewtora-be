<?php

namespace Tests\Feature\Core;

use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\LearnerProfile;
use App\Domains\Core\Jobs\ConfirmClassEnrollmentPayment;
use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Models\PaymentView;
use App\Domains\Core\Models\TewtoraClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SeedsAuthGraph;
use Tests\Concerns\SeedsClasses;
use Tests\TestCase;

/**
 * Paying for a Tewtora class end to end from Core's side: starting the
 * Paystack checkout (with the frontend's redirect_url), and verifying the
 * payment when the payer comes back — which must prove the learner is in
 * the class, and never confirm a seat on a payment made for something else.
 */
class ClassPaymentFlowHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;
    use SeedsClasses;

    private Account $parent;

    private LearnerProfile $child;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakePaymentService();
        config(['app.frontend_url' => 'https://app.tewtora.com', 'classes.checkout_redirect_hosts' => ['staging.tewtora.com']]);

        $this->parent = $this->makeAccount(['email' => 'parent-'.uniqid().'@example.test']);
        $this->child = $this->makeLearnerProfile(['owner_account_id' => $this->parent->id, 'profile_type' => 'child', 'full_name' => 'Ada']);
        $this->token = $this->tokenFor($this->parent);
    }

    private function as(string $token)
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function enroll(TewtoraClass $class, array $body = [])
    {
        return $this->as($this->token)->postJson("/api/v1/classes/{$class->public_id}/enrolments", ['learner_id' => $this->child->public_id] + $body);
    }

    private function enrolled(TewtoraClass $class, array $body = []): ClassEnrollment
    {
        return ClassEnrollment::where('public_id', $this->enroll($class, $body)->assertCreated()->json('data.id'))->sole();
    }

    private function verifyByReference(string $reference, ?string $token = null)
    {
        return $this->as($token ?? $this->token)->postJson('/api/v1/class-enrollments/verify-payment', ['reference' => $reference]);
    }

    private function sentCallbackUrl(): ?string
    {
        $sent = Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/internal/checkouts'))->last();

        return $sent ? $sent[0]['callback_url'] : null;
    }

    // --- init: redirect_url ---------------------------------------------------

    public function test_without_a_redirect_url_paystack_returns_to_the_default_complete_page(): void
    {
        $class = $this->makeClass();
        $enrollment = $this->enrolled($class);

        $this->assertSame("https://app.tewtora.com/classes/{$class->public_id}/enrollments/{$enrollment->public_id}/complete", $this->sentCallbackUrl());
    }

    public function test_an_allowlisted_redirect_url_is_where_paystack_returns_the_payer(): void
    {
        $this->enroll($this->makeClass(), ['redirect_url' => 'https://staging.tewtora.com/pay/done?from=classes'])
            ->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://checkout.paystack.test/abc123');

        $this->assertSame('https://staging.tewtora.com/pay/done?from=classes', $this->sentCallbackUrl());
    }

    public function test_the_frontend_urls_own_host_is_always_allowed(): void
    {
        $this->enroll($this->makeClass(), ['redirect_url' => 'https://app.tewtora.com/somewhere'])->assertCreated();
    }

    /** No open redirect: a genuine Paystack page must never hand the payer to someone else's site. */
    public function test_redirect_urls_off_the_allowlist_are_rejected_before_any_checkout_starts(): void
    {
        $class = $this->makeClass();

        foreach ([
            'https://evil.example.com/phish',
            'https://app.tewtora.com.evil.example.com/',  // suffix trick
            'https://evil.example.com/?next=app.tewtora.com',
            'http://app.tewtora.com/done',                // not https
            'https://user:pass@app.tewtora.com/done',     // credentials
            'https://app.tewtora.com:8443/done',          // port
            'https://app.tewtora.com/done#frag',          // fragment swallows ?reference=
            'javascript:alert(1)',
            '//app.tewtora.com/done',                     // scheme-relative
            'not a url',
        ] as $url) {
            $this->enroll($class, ['redirect_url' => $url])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('redirect_url', 'error.fields');
        }

        Http::assertNothingSent();
        $this->assertSame(0, ClassEnrollment::count());
    }

    public function test_localhost_over_http_is_allowed_outside_production_for_frontend_dev(): void
    {
        $this->enroll($this->makeClass(), ['redirect_url' => 'http://localhost:3000/classes/done'])->assertCreated();
        $this->assertSame('http://localhost:3000/classes/done', $this->sentCallbackUrl());
    }

    // --- verify ---------------------------------------------------------------

    public function test_verifying_by_paystacks_reference_confirms_the_seat_and_says_so(): void
    {
        $enrollment = $this->enrolled($this->makeClass());
        $this->fakePaymentService(status: ['channel' => 'card', 'paid_at' => '2026-11-12T10:45:10Z']);

        $this->verifyByReference($enrollment->payment_id)
            ->assertOk()
            ->assertJsonPath('data.id', $enrollment->public_id)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('verification.payment_status', 'paid')
            ->assertJsonPath('verification.enrolled', true)
            ->assertJsonPath('verification.channel', 'card')
            ->assertJsonPath('verification.reference', 'ref-123');

        $this->assertSame(ClassEnrollment::STATUS_CONFIRMED, $enrollment->fresh()->status);
        $this->assertSame('paid', PaymentView::where('payment_id', $enrollment->payment_id)->value('status'));
    }

    public function test_paystacks_trxref_works_too_and_the_provider_reference_resolves(): void
    {
        $enrollment = $this->enrolled($this->makeClass());

        $this->as($this->token)->postJson('/api/v1/class-enrollments/verify-payment', ['trxref' => 'ref-123'])
            ->assertOk()->assertJsonPath('data.id', $enrollment->public_id);
    }

    public function test_another_familys_reference_and_unknown_references_are_both_404(): void
    {
        $enrollment = $this->enrolled($this->makeClass());
        $stranger = $this->tokenFor($this->makeAccount(['email' => 'stranger@example.test']));

        $this->verifyByReference($enrollment->payment_id, $stranger)->assertNotFound();
        $this->verifyByReference('no-such-reference')->assertNotFound();
        $this->verifyByReference('bad reference!')->assertUnprocessable();
        $this->as($this->token)->postJson('/api/v1/class-enrollments/verify-payment', [])->assertUnprocessable();

        $this->assertSame(ClassEnrollment::STATUS_PENDING, $enrollment->fresh()->status);
    }

    /** "Verify validates participation": a payment made for anything else never buys this seat. */
    public function test_a_payment_that_payment_says_was_for_something_else_never_confirms(): void
    {
        $enrollment = $this->enrolled($this->makeClass());

        foreach ([['purpose_ref' => 'some-other-enrolment'], ['purpose' => 'plan'], ['payment_id' => 'pay-somebody-else']] as $lie) {
            $this->fakePaymentService(status: $lie);

            $this->as($this->token)->postJson("/api/v1/class-enrollments/{$enrollment->public_id}/verify-payment")
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'payment_mismatch');
        }

        $this->assertSame(ClassEnrollment::STATUS_PENDING, $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->paid_at);
    }

    public function test_the_wrong_amount_is_never_confirmed(): void
    {
        $enrollment = $this->enrolled($this->makeClass());
        $this->fakePaymentService(status: ['amount_minor' => 100]);

        $this->verifyByReference($enrollment->payment_id)
            ->assertOk()
            ->assertJsonPath('verification.payment_status', 'pending')
            ->assertJsonPath('verification.enrolled', false);
    }

    public function test_a_declined_card_reports_failed_then_a_retry_on_the_same_checkout_confirms(): void
    {
        $enrollment = $this->enrolled($this->makeClass());

        $this->fakePaymentService(status: ['status' => 'failed']);
        $this->verifyByReference($enrollment->payment_id)
            ->assertJsonPath('verification.payment_status', 'failed')
            ->assertJsonPath('verification.enrolled', false)
            ->assertJsonPath('data.status', 'pending_payment');
        $this->assertSame('failed', PaymentView::where('payment_id', $enrollment->payment_id)->value('status'));

        $this->fakePaymentService();
        $this->verifyByReference($enrollment->payment_id)
            ->assertJsonPath('verification.payment_status', 'paid')
            ->assertJsonPath('verification.enrolled', true);
        $this->assertSame('paid', PaymentView::where('payment_id', $enrollment->payment_id)->value('status'));
    }

    public function test_still_processing_reports_pending(): void
    {
        $enrollment = $this->enrolled($this->makeClass());
        $this->fakePaymentService(status: ['status' => 'processing']);

        $this->verifyByReference($enrollment->payment_id)
            ->assertJsonPath('verification.payment_status', 'pending')
            ->assertJsonPath('verification.enrolled', false)
            ->assertJsonPath('verification.paid_at', null);
    }

    /** Money landed but the class was cancelled meanwhile: paid, yet not in the class. */
    public function test_paid_for_a_class_cancelled_meanwhile_is_paid_but_not_enrolled(): void
    {
        $class = $this->makeClass();
        $enrollment = $this->enrolled($class);
        $enrollment->update(['status' => ClassEnrollment::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $this->verifyByReference($enrollment->payment_id)
            ->assertOk()
            ->assertJsonPath('verification.payment_status', 'paid')
            ->assertJsonPath('verification.enrolled', false)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_once_paid_verify_answers_locally_without_calling_payment(): void
    {
        $enrollment = $this->enrolled($this->makeClass());
        $this->verifyByReference($enrollment->payment_id)->assertJsonPath('verification.enrolled', true);

        $this->fakePaymentService();
        $this->verifyByReference($enrollment->payment_id)->assertJsonPath('verification.payment_status', 'paid');
        Http::assertNothingSent();
    }

    public function test_a_free_class_needs_no_payment_and_is_already_enrolled(): void
    {
        $enrollment = $this->enrolled($this->makeClass(['price_minor' => 0]));

        $this->as($this->token)->postJson("/api/v1/class-enrollments/{$enrollment->public_id}/verify-payment")
            ->assertOk()
            ->assertJsonPath('verification.payment_status', 'not_required')
            ->assertJsonPath('verification.enrolled', true);
        Http::assertNothingSent();
    }

    public function test_an_event_for_a_different_payment_than_the_enrolments_checkout_never_confirms(): void
    {
        $enrollment = $this->enrolled($this->makeClass());

        ConfirmClassEnrollmentPayment::dispatchSync([
            'payment_id' => 'pay-not-this-checkout',
            'purpose' => 'class_enrollment',
            'purpose_ref' => $enrollment->public_id,
            'amount_minor' => $enrollment->amount_minor,
            'currency_code' => 'NGN',
        ]);

        $this->assertSame(ClassEnrollment::STATUS_PENDING, $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->paid_at);
    }
}
