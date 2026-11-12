<?php

namespace Tests\Feature\Payment;

use App\Domains\Payment\Models\OutboxEvent;
use App\Domains\Payment\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** /internal/checkouts (Core -> Payment) and /webhooks/paystack (Paystack -> Payment). */
class CheckoutHttpTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.internal.token' => 'internal-secret',
            'services.paystack.secret_key' => self::SECRET,
            'services.paystack.base_url' => 'https://api.paystack.test',
        ]);
        $this->fakePaystack();
    }

    private function fakePaystack(array $verify = [], int $initializeStatus = 200): void
    {
        Http::swap(new Factory);
        Http::fake([
            'api.paystack.test/transaction/initialize' => fn (HttpRequest $r) => Http::response($initializeStatus === 200
                ? ['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/'.$r['reference'], 'reference' => $r['reference']]]
                : ['status' => false, 'message' => 'Invalid key'], $initializeStatus),
            'api.paystack.test/transaction/verify/*' => fn (HttpRequest $r) => Http::response(['status' => true, 'data' => array_merge([
                'reference' => basename($r->url()),
                'status' => 'success',
                'amount' => 2500000,
                'currency' => 'NGN',
                'channel' => 'card',
                'paid_at' => '2026-11-07T10:00:00.000Z',
            ], $verify)]),
        ]);
    }

    private function internal()
    {
        return $this->withHeader('X-Internal-Token', 'internal-secret');
    }

    private function checkoutBody(array $overrides = []): array
    {
        return array_merge([
            'purpose' => 'class_enrollment',
            'purpose_ref' => 'enrollment-uuid-1',
            'payer_account_id' => 42,
            'email' => 'parent@example.test',
            'amount_minor' => 2500000,
            'currency_code' => 'NGN',
            'callback_url' => 'https://app.tewtora.com/classes/c1/enrollments/e1/complete',
            'description' => 'Maths Bootcamp — Ada',
        ], $overrides);
    }

    private function startCheckout(array $overrides = []): Payment
    {
        $id = $this->internal()->postJson('/api/v1/internal/checkouts', $this->checkoutBody($overrides))->assertCreated()->json('data.payment_id');

        return Payment::where('public_id', $id)->sole();
    }

    private function webhook(array $event)
    {
        $body = json_encode($event);

        return $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::SECRET),
        ], $body);
    }

    private function chargeSuccess(Payment $payment, array $data = []): array
    {
        return ['event' => 'charge.success', 'data' => array_merge([
            'reference' => $payment->provider_reference,
            'status' => 'success',
            'amount' => $payment->amount_minor,
            'currency' => $payment->currency_code,
            'channel' => 'bank_transfer',
            'paid_at' => '2026-11-07T10:00:00.000Z',
        ], $data)];
    }

    // --- internal API -------------------------------------------------------

    public function test_internal_routes_need_the_shared_token(): void
    {
        $this->postJson('/api/v1/internal/checkouts', $this->checkoutBody())->assertUnauthorized();
        $this->withHeader('X-Internal-Token', 'wrong')->postJson('/api/v1/internal/checkouts', $this->checkoutBody())->assertUnauthorized();

        config(['services.internal.token' => null]);
        $this->withHeader('X-Internal-Token', '')->postJson('/api/v1/internal/checkouts', $this->checkoutBody())->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_starting_a_checkout_initializes_paystack_and_returns_its_url(): void
    {
        $response = $this->internal()->postJson('/api/v1/internal/checkouts', $this->checkoutBody())
            ->assertCreated()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.amount_minor', 2500000);

        $payment = Payment::where('public_id', $response->json('data.payment_id'))->sole();
        $this->assertSame("https://checkout.paystack.com/{$payment->public_id}", $response->json('data.checkout_url'));
        $this->assertSame($payment->public_id, $payment->provider_reference);
        $this->assertSame('paystack', $payment->provider->code);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/transaction/initialize')
            && $r->hasHeader('Authorization', 'Bearer '.self::SECRET)
            && $r['amount'] === 2500000
            && $r['currency'] === 'NGN'
            && $r['email'] === 'parent@example.test'
            && $r['reference'] === $payment->public_id
            && $r['metadata']['purpose_ref'] === 'enrollment-uuid-1');
    }

    public function test_the_same_purpose_ref_never_starts_a_second_charge(): void
    {
        $first = $this->internal()->postJson('/api/v1/internal/checkouts', $this->checkoutBody())->assertCreated()->json('data.payment_id');
        $this->internal()->postJson('/api/v1/internal/checkouts', $this->checkoutBody())->assertOk()->assertJsonPath('data.payment_id', $first);

        Http::assertSentCount(1);
        $this->assertSame(1, Payment::count());
    }

    public function test_a_paystack_failure_marks_the_payment_failed_and_returns_502(): void
    {
        $this->fakePaystack(initializeStatus: 401);

        $this->internal()->postJson('/api/v1/internal/checkouts', $this->checkoutBody())
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'payment_provider_error')
            ->assertJsonMissingPath('error.detail');

        $this->assertSame('failed', Payment::sole()->status);
    }

    public function test_free_or_unknown_purposes_are_rejected(): void
    {
        $this->internal()->postJson('/api/v1/internal/checkouts', $this->checkoutBody(['amount_minor' => 0]))->assertStatus(422);
        $this->internal()->postJson('/api/v1/internal/checkouts', $this->checkoutBody(['purpose' => 'anything']))->assertStatus(422);
    }

    public function test_status_reverifies_with_paystack_and_settles_once(): void
    {
        $payment = $this->startCheckout();

        $this->internal()->getJson("/api/v1/internal/checkouts/{$payment->public_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.checkout_url', null);
        $this->internal()->getJson("/api/v1/internal/checkouts/{$payment->public_id}")->assertOk();

        $this->assertSame('card', $payment->fresh()->channel);
        $this->assertSame(1, OutboxEvent::where('event_type', 'PaymentSucceeded')->count());
        Http::assertSentCount(2); // initialize + one verify; the second status read is local
    }

    public function test_status_tells_core_what_the_payment_was_for(): void
    {
        $payment = $this->startCheckout();

        $this->internal()->getJson("/api/v1/internal/checkouts/{$payment->public_id}")
            ->assertJsonPath('data.purpose', 'class_enrollment')
            ->assertJsonPath('data.purpose_ref', 'enrollment-uuid-1')
            ->assertJsonPath('data.channel', 'card');
    }

    /** The payer's "I've paid" after a declined card and a retry on the same Paystack page. */
    public function test_status_reverifies_a_failed_payment_and_lets_success_win(): void
    {
        $payment = $this->startCheckout();
        $this->fakePaystack(verify: ['status' => 'failed', 'gateway_response' => 'Declined']);
        $this->internal()->getJson("/api/v1/internal/checkouts/{$payment->public_id}")->assertJsonPath('data.status', 'failed');

        $this->fakePaystack();
        $this->internal()->getJson("/api/v1/internal/checkouts/{$payment->public_id}")->assertJsonPath('data.status', 'success');

        $this->assertNull($payment->fresh()->failure_reason);
        $this->assertSame(['PaymentFailed', 'PaymentSucceeded'], OutboxEvent::orderBy('id')->pluck('event_type')->all());
    }

    public function test_status_never_reverifies_a_checkout_that_never_started(): void
    {
        $this->fakePaystack(initializeStatus: 401);
        $this->internal()->postJson('/api/v1/internal/checkouts', $this->checkoutBody())->assertStatus(502);
        $this->fakePaystack();

        $this->internal()->getJson('/api/v1/internal/checkouts/'.Payment::sole()->public_id)->assertJsonPath('data.status', 'failed');
        Http::assertNothingSent();
    }

    public function test_status_leaves_an_abandoned_checkout_processing(): void
    {
        $payment = $this->startCheckout();
        $this->fakePaystack(verify: ['status' => 'abandoned']);

        $this->internal()->getJson("/api/v1/internal/checkouts/{$payment->public_id}")->assertOk()->assertJsonPath('data.status', 'processing');
        $this->assertSame(0, OutboxEvent::count());
    }

    // --- webhook ------------------------------------------------------------

    public function test_a_badly_signed_webhook_is_rejected_and_changes_nothing(): void
    {
        $payment = $this->startCheckout();

        $this->withHeader('x-paystack-signature', 'forged')
            ->postJson('/api/v1/webhooks/paystack', $this->chargeSuccess($payment))
            ->assertUnauthorized();

        $this->assertSame('processing', $payment->fresh()->status);
        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_a_signed_charge_success_settles_and_publishes_payment_succeeded(): void
    {
        $payment = $this->startCheckout();

        $this->webhook($this->chargeSuccess($payment))->assertOk();

        $payment->refresh();
        $this->assertSame('success', $payment->status);
        $this->assertSame('bank_transfer', $payment->channel);

        $event = OutboxEvent::where('event_type', 'PaymentSucceeded')->sole();
        // assertEquals: MySQL's JSON type doesn't keep key order.
        $this->assertEquals([
            'payment_id' => $payment->public_id,
            'purpose' => 'class_enrollment',
            'purpose_ref' => 'enrollment-uuid-1',
            'amount_minor' => 2500000,
            'currency_code' => 'NGN',
            'provider_reference' => $payment->provider_reference,
            'channel' => 'bank_transfer',
            'paid_at' => '2026-11-07T10:00:00Z',
        ], $event->payload);
        $this->assertNull($event->published_at);
    }

    /** Paystack retries webhooks: the same event twice must settle once. */
    public function test_a_repeated_webhook_is_a_no_op(): void
    {
        $payment = $this->startCheckout();

        $this->webhook($this->chargeSuccess($payment))->assertOk();
        $this->webhook($this->chargeSuccess($payment))->assertOk();

        $this->assertSame(1, OutboxEvent::count());
    }

    public function test_a_webhook_for_the_wrong_amount_never_settles(): void
    {
        $payment = $this->startCheckout();

        $this->webhook($this->chargeSuccess($payment, ['amount' => 100]))->assertOk();

        $this->assertSame('processing', $payment->fresh()->status);
        $this->assertSame('amount_mismatch', $payment->fresh()->failure_reason, 'flagged for a human');
        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_a_failed_charge_publishes_payment_failed(): void
    {
        $payment = $this->startCheckout();

        $this->webhook(['event' => 'charge.failed', 'data' => ['reference' => $payment->provider_reference, 'status' => 'failed', 'amount' => 2500000, 'currency' => 'NGN', 'gateway_response' => 'Declined']])->assertOk();

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame('Declined', $payment->fresh()->failure_reason);
        $this->assertSame(1, OutboxEvent::where('event_type', 'PaymentFailed')->count());
    }

    /** Money taken must always reach Core: a declined first attempt is not final. */
    public function test_a_success_webhook_after_a_failure_still_settles_as_success(): void
    {
        $payment = $this->startCheckout();

        $this->webhook(['event' => 'charge.failed', 'data' => ['reference' => $payment->provider_reference, 'status' => 'failed', 'amount' => 2500000, 'currency' => 'NGN', 'gateway_response' => 'Declined']])->assertOk();
        $this->webhook($this->chargeSuccess($payment))->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertSame(['PaymentFailed', 'PaymentSucceeded'], OutboxEvent::orderBy('id')->pluck('event_type')->all());
    }

    public function test_a_late_failure_never_undoes_a_success(): void
    {
        $payment = $this->startCheckout();

        $this->webhook($this->chargeSuccess($payment))->assertOk();
        $this->webhook(['event' => 'charge.failed', 'data' => ['reference' => $payment->provider_reference, 'status' => 'failed', 'amount' => 2500000, 'currency' => 'NGN']])->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertSame(1, OutboxEvent::count());
    }

    // --- reconciliation (§7.4) ----------------------------------------------

    public function test_reconcile_settles_missed_webhooks_and_leaves_the_rest_alone(): void
    {
        $missed = $this->startCheckout();
        $tooNew = $this->startCheckout(['purpose_ref' => 'enrollment-uuid-2']);
        $tooOld = $this->startCheckout(['purpose_ref' => 'enrollment-uuid-3']);
        $mismatch = $this->startCheckout(['purpose_ref' => 'enrollment-uuid-4']);
        $settled = $this->startCheckout(['purpose_ref' => 'enrollment-uuid-5']);

        $missed->forceFill(['created_at' => now()->subMinutes(30)])->save();
        $tooOld->forceFill(['created_at' => now()->subDays(3)])->save();
        $mismatch->forceFill(['created_at' => now()->subMinutes(30), 'failure_reason' => 'amount_mismatch'])->save();
        $settled->forceFill(['created_at' => now()->subMinutes(30), 'status' => 'success', 'paid_at' => now()])->save();

        $this->fakePaystack();
        $this->artisan('payments:reconcile')->expectsOutputToContain('Checked 1, settled 1, errors 0')->assertSuccessful();

        $this->assertSame('success', $missed->fresh()->status);
        $this->assertSame('processing', $tooNew->fresh()->status);
        $this->assertSame('processing', $tooOld->fresh()->status);
        $this->assertSame('processing', $mismatch->fresh()->status);
        $this->assertSame(1, OutboxEvent::where('event_type', 'PaymentSucceeded')->count());
        Http::assertSentCount(1);
    }

    public function test_reconcile_carries_on_past_a_provider_error(): void
    {
        $a = $this->startCheckout();
        $b = $this->startCheckout(['purpose_ref' => 'enrollment-uuid-2']);
        Payment::query()->update(['created_at' => now()->subHour()]);

        Http::swap(new Factory);
        Http::fake([
            "api.paystack.test/transaction/verify/{$a->public_id}" => Http::response(['status' => false, 'message' => 'boom'], 500),
            'api.paystack.test/transaction/verify/*' => Http::response(['status' => true, 'data' => [
                'reference' => $b->public_id, 'status' => 'success', 'amount' => 2500000, 'currency' => 'NGN', 'channel' => 'card',
            ]]),
        ]);

        $this->artisan('payments:reconcile')->expectsOutputToContain('Checked 2, settled 1, errors 1')->assertSuccessful();
        $this->assertSame('processing', $a->fresh()->status);
        $this->assertSame('success', $b->fresh()->status);
    }

    public function test_unknown_references_and_other_events_are_acknowledged_and_ignored(): void
    {
        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => 'not-ours', 'status' => 'success', 'amount' => 1, 'currency' => 'NGN']])->assertOk();
        $this->webhook(['event' => 'transfer.success', 'data' => ['reference' => 'x']])->assertOk();

        $this->assertSame(0, OutboxEvent::count());
    }
}
