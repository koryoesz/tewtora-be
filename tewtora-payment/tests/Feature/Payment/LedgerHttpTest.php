<?php

namespace Tests\Feature\Payment;

use App\Domains\Payment\Models\PaymentLineItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsPaymentGraph;
use Tests\TestCase;

/**
 * Covers the GatewayPrincipal/TrustGatewayIdentity stand-in
 * (app/Shared/Auth) — see its docblock and
 * docs/api-gap-analysis.md §7's update for why this exists instead of
 * Payment authenticating callers itself.
 */
class LedgerHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPaymentGraph;

    private function gatewayHeaders(int $accountId, string $accountType, ?int $teacherId = null): array
    {
        return array_filter([
            'X-Gateway-Account-Id' => (string) $accountId,
            'X-Gateway-Account-Type' => $accountType,
            'X-Gateway-Teacher-Id' => $teacherId !== null ? (string) $teacherId : null,
        ]);
    }

    public function test_requests_without_gateway_headers_are_rejected(): void
    {
        $response = $this->getJson('/api/v1/teachers/1/ledger');

        $response->assertStatus(401);
    }

    public function test_a_teacher_can_view_their_own_ledger(): void
    {
        $payment = $this->makePayment();
        PaymentLineItem::create([
            'payment_id' => $payment->id,
            'session_id' => 1,
            'teacher_id' => 42,
            'amount_minor' => 500000,
            'status' => 'held',
        ]);

        $response = $this->withHeaders($this->gatewayHeaders(7, 'teacher', 42))
            ->getJson('/api/v1/teachers/42/ledger');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_teacher_cannot_view_another_teachers_ledger(): void
    {
        $response = $this->withHeaders($this->gatewayHeaders(7, 'teacher', 42))
            ->getJson('/api/v1/teachers/99/ledger');

        $response->assertForbidden();
    }
}
