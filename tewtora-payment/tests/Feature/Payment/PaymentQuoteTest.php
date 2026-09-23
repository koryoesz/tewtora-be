<?php

namespace Tests\Feature\Payment;

use App\Domains\Payment\Models\PaymentQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsPaymentGraph;
use Tests\TestCase;

/**
 * Covers 2024_02_01_000020/000022: `/checkout/quote` returns a
 * server-computed total the confirm screen trusts, and `/checkout/pay`
 * redeems it by id — "the frontend must never render a total it computed
 * itself" (docs/api-contract.md §7).
 */
class PaymentQuoteTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPaymentGraph;

    private function makeQuote(array $overrides = []): PaymentQuote
    {
        return PaymentQuote::create(array_merge([
            'payer_account_id' => 1,
            'teacher_id' => 1,
            'plan_kind' => 'monthly',
            'session_count' => 8,
            'line_items' => [['label' => '8 sessions', 'amount_minor' => 4000000]],
            'total_minor' => 4000000,
            'expires_at' => now()->addMinutes(15),
        ], $overrides));
    }

    public function test_a_payment_can_be_redeemed_against_a_quote(): void
    {
        $quote = $this->makeQuote();

        $payment = $this->makePayment(['quote_id' => $quote->id, 'amount_minor' => $quote->total_minor]);

        $this->assertSame($quote->id, $payment->fresh()->quote_id);
        $this->assertSame($quote->total_minor, $payment->fresh()->amount_minor);
    }

    public function test_deleting_a_quote_nulls_out_quote_id_on_its_payment_instead_of_blocking(): void
    {
        $quote = $this->makeQuote();
        $payment = $this->makePayment(['quote_id' => $quote->id]);

        $quote->delete();

        $this->assertNull($payment->fresh()->quote_id);
    }

    public function test_line_items_round_trip_as_an_array(): void
    {
        $quote = $this->makeQuote();

        $this->assertSame(
            [['label' => '8 sessions', 'amount_minor' => 4000000]],
            $quote->fresh()->line_items
        );
    }
}
