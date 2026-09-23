<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §0 needs a `child | admin` account_type alongside
 * the original three. A child's own login is
 * auth.learner_profiles.linked_login_account_id -> an accounts row, which
 * had nowhere to record that under the old CHECK. `admin` covers internal
 * staff (verification review, safeguarding, support "act-as"). See
 * docs/api-gap-analysis.md §0 for why this reuses `accounts` rather than a
 * separate staff table.
 *
 * The original CHECK on account_type was unnamed, so MySQL auto-generated
 * its constraint name — looked up from information_schema rather than
 * guessed, since that name isn't guaranteed across MySQL versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $constraint = $this->findAccountTypeCheckConstraint();

        if ($constraint !== null) {
            DB::statement("ALTER TABLE auth.accounts DROP CHECK `{$constraint}`");
        }

        DB::statement(<<<'SQL'
            ALTER TABLE auth.accounts
              ADD CONSTRAINT chk_accounts_account_type
              CHECK (account_type IN ('parent','independent_student','child','teacher','admin'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE auth.accounts DROP CHECK chk_accounts_account_type');

        DB::statement(<<<'SQL'
            ALTER TABLE auth.accounts
              ADD CONSTRAINT chk_accounts_account_type
              CHECK (account_type IN ('parent','independent_student','teacher'))
        SQL);
    }

    private function findAccountTypeCheckConstraint(): ?string
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT cc.CONSTRAINT_NAME AS name
            FROM information_schema.CHECK_CONSTRAINTS cc
            JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA
             AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE cc.CONSTRAINT_SCHEMA = 'auth'
              AND tc.TABLE_NAME = 'accounts'
              AND cc.CHECK_CLAUSE LIKE '%account_type%'
        SQL);

        return $row?->name;
    }
};
