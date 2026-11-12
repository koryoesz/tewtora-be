<?php

namespace Tests\Feature\Core;

use App\Console\Commands\ConsumePaymentEvents;
use App\Domains\Auth\Models\Account;
use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Models\PaymentView;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\Concerns\SeedsAuthGraph;
use Tests\Concerns\SeedsClasses;
use Tests\TestCase;

/** /internal/payments — the staff Payments page, and the payment_view read model behind it. */
class AdminPaymentsHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;
    use SeedsClasses;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakePaymentService();
        $this->adminToken = $this->tokenFor($this->makeAccount(['account_type' => 'admin']));
    }

    private function as(string $token)
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function admin()
    {
        return $this->as($this->adminToken);
    }

    /** Enrols a new family's child in $class through the real HTTP flow; returns the pending enrolment. */
    private function enrolFamily($class, string $email = 'ngozi@example.test', string $child = 'Ada'): ClassEnrollment
    {
        $parent = $this->makeAccount(['email' => $email]);
        $learner = $this->makeLearnerProfile(['owner_account_id' => $parent->id, 'profile_type' => 'child', 'full_name' => $child]);

        $id = $this->as($this->tokenFor($parent))
            ->postJson("/api/v1/classes/{$class->public_id}/enrolments", ['learner_id' => $learner->public_id])
            ->assertCreated()->json('data.id');

        return ClassEnrollment::where('public_id', $id)->sole();
    }

    private function deliver(string $type, ClassEnrollment $enrollment, array $extra = []): void
    {
        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getBody')->andReturn(json_encode(['event_type' => $type, 'payload' => array_merge([
            'payment_id' => $enrollment->payment_id,
            'purpose' => 'class_enrollment',
            'purpose_ref' => $enrollment->public_id,
            'amount_minor' => $enrollment->amount_minor,
            'currency_code' => $enrollment->currency_code,
            'provider_reference' => 'ref-123',
        ], $extra)]));
        $message->shouldReceive('ack')->once();
        $message->shouldNotReceive('nack');

        app(ConsumePaymentEvents::class)->handleMessage($message);
    }

    private function row(array $overrides = []): PaymentView
    {
        static $n = 0;
        $n++;

        return PaymentView::create(array_merge([
            'payment_id' => "pay-{$n}",
            'purpose' => 'class_enrollment',
            'purpose_ref' => "enr-{$n}",
            'payer_account_id' => 1,
            'payer_account_public_id' => 'acct-uuid',
            'payer_email' => "payer{$n}@example.test",
            'learner_name' => 'Ada',
            'for_label' => 'Algebra Foundations',
            'reference' => "TWT-{$n}",
            'amount_minor' => 1000,
            'currency_code' => 'NGN',
            'status' => 'pending',
            'created_at' => now(),
        ], $overrides));
    }

    public function test_only_admins_can_see_payments(): void
    {
        $parent = $this->tokenFor($this->makeAccount());

        $this->as($parent)->getJson('/api/v1/internal/payments')->assertForbidden();
        $this->as($parent)->getJson('/api/v1/internal/payments/summary')->assertForbidden();
        Auth::forgetGuards();
        $this->flushHeaders()->getJson('/api/v1/internal/payments')->assertUnauthorized();
    }

    /** End to end: checkout started -> pending; PaymentSucceeded -> paid, with channel and paid_at. */
    public function test_a_class_payment_appears_pending_then_paid_with_the_row_shape_the_table_needs(): void
    {
        $class = $this->makeClass(['title' => 'Algebra Foundations']);
        $enrollment = $this->enrolFamily($class);

        $row = $this->admin()->getJson('/api/v1/internal/payments')->assertOk()
            ->assertJsonPath('meta', ['total' => 1, 'page' => 1, 'per_page' => 12])
            ->json('data.0');

        $this->assertSame($enrollment->payment_id, $row['id']);
        $this->assertSame([
            'account_id' => Account::whereKey($enrollment->payer_account_id)->value('public_id'),
            'name' => 'ngozi@example.test', // parents have no name on record
            'email' => 'ngozi@example.test',
        ], $row['payer']);
        $this->assertSame('Ada', $row['learner_name']);
        $this->assertSame('class_enrollment', $row['purpose']);
        $this->assertSame('Algebra Foundations', $row['for_label']);
        $this->assertSame('ref-123', $row['reference']);
        $this->assertSame(2500000, $row['amount_minor']);
        $this->assertSame('NGN', $row['currency_code']);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['channel']);
        $this->assertNull($row['paid_at']);
        $this->assertStringEndsWith('Z', $row['created_at']);

        $this->deliver('PaymentSucceeded', $enrollment, ['channel' => 'card', 'paid_at' => '2026-11-12T10:45:10Z']);

        $this->admin()->getJson('/api/v1/internal/payments')
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.channel', 'card')
            ->assertJsonPath('data.0.paid_at', '2026-11-12T10:45:10Z');
        $this->assertSame(ClassEnrollment::STATUS_CONFIRMED, $enrollment->fresh()->status);
    }

    public function test_an_independent_students_own_name_is_the_payer_name(): void
    {
        $student = $this->makeAccount(['account_type' => 'independent_student', 'email' => 'tolu@example.test']);
        $learner = $this->makeLearnerProfile(['owner_account_id' => $student->id, 'profile_type' => 'own', 'full_name' => 'Tolu Ade']);
        $class = $this->makeClass();

        $this->as($this->tokenFor($student))
            ->postJson("/api/v1/classes/{$class->public_id}/enrolments", ['learner_id' => $learner->public_id])->assertCreated();

        $this->admin()->getJson('/api/v1/internal/payments')->assertJsonPath('data.0.payer.name', 'Tolu Ade');
    }

    /** At-least-once, any order: a failure never overrides a payment already recorded as paid. */
    public function test_payment_failed_marks_the_row_failed_and_never_undoes_a_paid_one(): void
    {
        $class = $this->makeClass();
        $failing = $this->enrolFamily($class, 'a@example.test', 'Ada');
        $paid = $this->enrolFamily($class, 'b@example.test', 'Bola');

        $this->deliver('PaymentFailed', $failing);
        $this->deliver('PaymentFailed', $failing); // redelivered
        $this->deliver('PaymentSucceeded', $paid, ['channel' => 'bank_transfer']);
        $this->deliver('PaymentFailed', $paid); // stale, out of order

        $this->assertSame('failed', PaymentView::where('payment_id', $failing->payment_id)->value('status'));
        $this->assertSame('paid', PaymentView::where('payment_id', $paid->payment_id)->value('status'));
        $this->assertSame(ClassEnrollment::STATUS_PENDING, $failing->fresh()->status, 'the seat hold still runs out on its own');
    }

    public function test_verify_payment_records_the_outcome_on_the_view_too(): void
    {
        $class = $this->makeClass();
        $enrollment = $this->enrolFamily($class);
        $this->fakePaymentService(status: ['payment_id' => $enrollment->payment_id, 'status' => 'success', 'channel' => 'card', 'paid_at' => '2026-11-12T09:00:00Z']);

        $parentToken = $this->tokenFor(Account::find($enrollment->payer_account_id));
        $this->as($parentToken)->postJson("/api/v1/class-enrollments/{$enrollment->public_id}/verify-payment")->assertOk();

        $this->admin()->getJson('/api/v1/internal/payments')
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.channel', 'card')
            ->assertJsonPath('data.0.paid_at', '2026-11-12T09:00:00Z');
    }

    public function test_free_classes_never_appear(): void
    {
        $class = $this->makeClass(['price_minor' => 0]);
        $this->enrolFamily($class);

        $this->admin()->getJson('/api/v1/internal/payments')->assertJsonPath('meta.total', 0);
    }

    public function test_search_filter_sort_and_pagination(): void
    {
        $this->row(['payer_name' => 'Ngozi Balogun', 'learner_name' => 'Ada', 'amount_minor' => 300, 'status' => 'paid', 'paid_at' => now(), 'created_at' => now()->subDays(3)]);
        $this->row(['payer_email' => 'zara@example.test', 'learner_name' => 'Kemi', 'for_label' => 'Phonics Club', 'amount_minor' => 100, 'created_at' => now()->subDays(1)]);
        $this->row(['payer_email' => 'abe@example.test', 'learner_name' => 'Tobi', 'reference' => 'TWT-SPECIAL', 'amount_minor' => 200, 'status' => 'failed', 'created_at' => now()->subDays(2)]);

        $ids = fn ($response) => array_column($response->assertOk()->json('data'), 'learner_name');

        $this->assertSame(['Kemi', 'Tobi', 'Ada'], $ids($this->admin()->getJson('/api/v1/internal/payments')), 'newest first by default');
        $this->assertSame(['Kemi', 'Tobi', 'Ada'], $ids($this->admin()->getJson('/api/v1/internal/payments?sort=amount&dir=asc')));
        $this->assertSame(['Tobi', 'Ada', 'Kemi'], $ids($this->admin()->getJson('/api/v1/internal/payments?sort=payer&dir=asc')), 'abe@, Ngozi, zara@');

        $this->assertSame(['Ada'], $ids($this->admin()->getJson('/api/v1/internal/payments?q=balogun')));
        $this->assertSame(['Kemi'], $ids($this->admin()->getJson('/api/v1/internal/payments?q=phonics')));
        $this->assertSame(['Tobi'], $ids($this->admin()->getJson('/api/v1/internal/payments?q=TWT-SPEC')));
        $this->assertSame(['Kemi'], $ids($this->admin()->getJson('/api/v1/internal/payments?q=zara@')));
        $this->assertSame([], $ids($this->admin()->getJson('/api/v1/internal/payments?q=%25')), 'LIKE wildcards are literal');
        $this->assertSame(['Tobi'], $ids($this->admin()->getJson('/api/v1/internal/payments?status=failed')));

        $this->admin()->getJson('/api/v1/internal/payments?per_page=2&page=2')
            ->assertJsonPath('meta', ['total' => 3, 'page' => 2, 'per_page' => 2])
            ->assertJsonCount(1, 'data');

        $this->admin()->getJson('/api/v1/internal/payments?per_page=101')->assertUnprocessable();
        $this->admin()->getJson('/api/v1/internal/payments?status=success')->assertUnprocessable();
        $this->admin()->getJson('/api/v1/internal/payments?sort=learner')->assertUnprocessable();
    }

    public function test_summary_defaults_to_the_current_lagos_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00', 'Africa/Lagos'));
        $inMonth = now()->subDays(2);

        $this->row(['status' => 'paid', 'amount_minor' => 5000, 'paid_at' => $inMonth, 'created_at' => $inMonth]);
        $this->row(['status' => 'paid', 'amount_minor' => 7000, 'paid_at' => $inMonth, 'created_at' => $inMonth]);
        $this->row(['status' => 'pending', 'amount_minor' => 900, 'created_at' => $inMonth]);
        $this->row(['status' => 'failed', 'amount_minor' => 400, 'created_at' => $inMonth]);
        // Out of the period / other currency: ignored.
        $this->row(['status' => 'paid', 'amount_minor' => 99999, 'paid_at' => CarbonImmutable::parse('2026-10-31 23:30', 'Africa/Lagos')->utc(), 'created_at' => CarbonImmutable::parse('2026-10-31 23:00', 'Africa/Lagos')->utc()]);
        $this->row(['status' => 'paid', 'amount_minor' => 50, 'currency_code' => 'USD', 'paid_at' => $inMonth, 'created_at' => $inMonth]);

        $this->admin()->getJson('/api/v1/internal/payments/summary')->assertOk()->assertExactJson([
            'collected_minor' => 12000, 'collected_count' => 2,
            'pending_minor' => 900, 'pending_count' => 1,
            'failed_count' => 1, 'failed_rate' => 25,
            'refunded_minor' => 0,
            'currency_code' => 'NGN',
        ]);

        $this->admin()->getJson('/api/v1/internal/payments/summary?from=2026-10-31&to=2026-10-31')
            ->assertJsonPath('collected_minor', 99999)
            ->assertJsonPath('failed_rate', 0);

        $this->admin()->getJson('/api/v1/internal/payments/summary?from=2026-11-10&to=2026-11-01')->assertUnprocessable();
    }

    public function test_sync_view_backfills_existing_enrolments_idempotently(): void
    {
        $class = $this->makeClass();
        $parent = $this->makeAccount(['email' => 'old@example.test']);
        $this->makeEnrollment($class, ['payer_account_id' => $parent->id, 'payment_id' => 'pay-old-1', 'paid_at' => now()]);
        $this->makeEnrollment($class, ['payer_account_id' => $parent->id, 'payment_id' => 'pay-old-2', 'status' => ClassEnrollment::STATUS_PENDING, 'confirmed_at' => null]);
        $this->makeEnrollment($class, ['payer_account_id' => $parent->id]); // free/no payment: skipped
        $this->row(['payment_id' => 'pay-old-2', 'purpose_ref' => 'x', 'status' => 'failed']); // already has an outcome

        $this->artisan('payments:sync-view')->assertSuccessful();
        $this->artisan('payments:sync-view')->assertSuccessful();

        $this->assertSame(2, PaymentView::count());
        $this->assertSame('paid', PaymentView::where('payment_id', 'pay-old-1')->value('status'));
        $this->assertSame('failed', PaymentView::where('payment_id', 'pay-old-2')->value('status'));
    }
}
