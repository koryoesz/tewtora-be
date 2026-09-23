<?php

namespace Tests\Feature\Payment;

use App\Domains\Payment\Models\CommissionTier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers 2024_02_01_000023: the commission-rate config docs/api-contract.md
 * §10's TeacherCommissionInfo needs — nothing recorded a platform
 * commission percentage anywhere before this table.
 */
class CommissionTierTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_and_reduced_rates_can_both_exist(): void
    {
        CommissionTier::create(['rate_type' => 'standard', 'rate' => 0.15]);
        CommissionTier::create(['rate_type' => 'reduced', 'rate' => 0.12, 'threshold_sessions' => 100]);

        $this->assertSame(2, CommissionTier::count());
    }

    public function test_a_rate_type_cannot_be_registered_twice(): void
    {
        CommissionTier::create(['rate_type' => 'standard', 'rate' => 0.15]);

        $this->expectException(QueryException::class);

        CommissionTier::create(['rate_type' => 'standard', 'rate' => 0.20]);
    }

    public function test_a_rate_outside_zero_to_one_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        CommissionTier::create(['rate_type' => 'standard', 'rate' => 1.5]);
    }
}
