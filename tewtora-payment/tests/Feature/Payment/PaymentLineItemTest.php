<?php

namespace Tests\Feature\Payment;

use App\Domains\Payment\Models\PaymentLineItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsPaymentGraph;
use Tests\TestCase;

/**
 * Covers 2024_02_01_000021: the per-session escrow row the product's
 * "hold full amount, release per session after feedback" rule needs
 * (docs/api-contract.md §7/§10, docs/api-gap-analysis.md §7).
 */
class PaymentLineItemTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPaymentGraph;

    public function test_default_status_is_held(): void
    {
        $payment = $this->makePayment();

        $lineItem = PaymentLineItem::create([
            'payment_id' => $payment->id,
            'session_id' => 1,
            'amount_minor' => 500000,
        ]);

        $this->assertSame('held', $lineItem->fresh()->status);
    }

    public function test_marking_released_without_a_released_at_is_rejected(): void
    {
        $payment = $this->makePayment();

        $this->expectException(QueryException::class);

        PaymentLineItem::create([
            'payment_id' => $payment->id,
            'session_id' => 1,
            'amount_minor' => 500000,
            'status' => 'released',
        ]);
    }

    public function test_marking_released_with_a_released_at_succeeds(): void
    {
        $payment = $this->makePayment();

        $lineItem = PaymentLineItem::create([
            'payment_id' => $payment->id,
            'session_id' => 1,
            'amount_minor' => 500000,
            'status' => 'released',
            'released_at' => now(),
        ]);

        $this->assertSame('released', $lineItem->fresh()->status);
    }

    public function test_a_held_line_item_needs_no_released_at(): void
    {
        $payment = $this->makePayment();

        $lineItem = PaymentLineItem::create([
            'payment_id' => $payment->id,
            'session_id' => 1,
            'amount_minor' => 500000,
            'status' => 'held',
        ]);

        $this->assertNull($lineItem->fresh()->released_at);
    }
}
