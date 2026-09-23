<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §4: `DELETE /plans/:id` "ends the plan" but must
 * retain history — plans has no deleted_at (only accounts/learner_profiles/
 * teachers get soft deletes, per database-design.md §1.4's append-only
 * rule for everything else), so this needs a third status value, not a
 * row deletion.
 *
 * Also fixes chk_plans_renews_at_if_active: as originally written
 * (status = 'paused' OR renews_at IS NOT NULL), an 'ended' plan would
 * incorrectly still be required to carry a renews_at. Rewritten to only
 * require it for 'active'. That constraint was explicitly named in the
 * original migration, so it's referenced directly — only the status
 * IN-list CHECK (originally inline/unnamed on the column definition) needs
 * a dynamic lookup, and even then by column presence rather than by
 * matching the value list's literal text: MySQL normalizes
 * CHECK_CLAUSE with charset prefixes (_utf8mb4'active'), so a pattern
 * built from the plain SQL text wouldn't reliably match.
 */
return new class extends Migration
{
    public function up(): void
    {
        $statusCheck = DB::selectOne("
            SELECT cc.CONSTRAINT_NAME AS name
            FROM information_schema.CHECK_CONSTRAINTS cc
            JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA
             AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE cc.CONSTRAINT_SCHEMA = 'core'
              AND tc.TABLE_NAME = 'plans'
              AND cc.CHECK_CLAUSE LIKE '%active%'
              AND cc.CHECK_CLAUSE LIKE '%paused%'
              AND cc.CHECK_CLAUSE NOT LIKE '%renews_at%'
        ")?->name;

        if ($statusCheck !== null) {
            DB::statement("ALTER TABLE core.plans DROP CHECK `{$statusCheck}`");
        }

        DB::statement(<<<'SQL'
            ALTER TABLE core.plans
              ADD CONSTRAINT chk_plans_status
              CHECK (status IN ('active','paused','ended'))
        SQL);

        DB::statement('ALTER TABLE core.plans DROP CHECK chk_plans_renews_at_if_active');
        DB::statement(<<<'SQL'
            ALTER TABLE core.plans
              ADD CONSTRAINT chk_plans_renews_at_if_active
              CHECK (status != 'active' OR renews_at IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE core.plans DROP CHECK chk_plans_renews_at_if_active');
        DB::statement(<<<'SQL'
            ALTER TABLE core.plans
              ADD CONSTRAINT chk_plans_renews_at_if_active
              CHECK (status = 'paused' OR renews_at IS NOT NULL)
        SQL);

        DB::statement('ALTER TABLE core.plans DROP CHECK chk_plans_status');
        DB::statement(<<<'SQL'
            ALTER TABLE core.plans
              ADD CONSTRAINT chk_plans_status_restored
              CHECK (status IN ('active','paused'))
        SQL);
    }
};
