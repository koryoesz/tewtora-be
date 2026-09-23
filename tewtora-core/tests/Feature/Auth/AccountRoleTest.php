<?php

namespace Tests\Feature\Auth;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAuthGraph;
use Tests\TestCase;

/**
 * Covers 2024_02_01_000050: account_type extended to 5 roles
 * (docs/api-contract.md §0). Also proves the constraint-name lookup in
 * that migration actually found and replaced the original CHECK, rather
 * than silently adding a second, redundant one.
 */
class AccountRoleTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;

    /** @dataProvider validAccountTypes */
    public function test_each_of_the_five_roles_is_accepted(string $accountType): void
    {
        $account = $this->makeAccount(['account_type' => $accountType]);

        $this->assertSame($accountType, $account->fresh()->account_type);
    }

    public static function validAccountTypes(): array
    {
        return [
            ['parent'],
            ['independent_student'],
            ['child'],
            ['teacher'],
            ['admin'],
        ];
    }

    public function test_an_invalid_account_type_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->makeAccount(['account_type' => 'not_a_real_role']);
    }
}
