<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Same gap as 2024_02_01_000100 (recommendation.matches), same cause:
 * 2024_02_01_000084 backfilled public_id but never gave the column a
 * DEFAULT, because ADD COLUMN ... DEFAULT (UUID()) is rejected by MySQL on
 * an ALTER TABLE under STATEMENT binlog format (error 1674). Unlike
 * core.teacher_account_links/core.learner_account_links (which mirror an
 * Auth-owned public_id and correctly should stay NOT NULL with no default
 * — see 2024_02_01_000102), a plan_goals row is a genuinely independent
 * Core-owned entity: nothing else's public_id to accidentally diverge
 * from. `ALTER TABLE ... ALTER COLUMN col SET DEFAULT (expr)` is a pure
 * metadata change, so it avoids the same replication-safety restriction
 * that ruled out setting the default at backfill time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (CrossDatabaseSchema::columnHasDefault('core', 'plan_goals', 'public_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE core.plan_goals
              ALTER COLUMN public_id SET DEFAULT (UUID());
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE core.plan_goals
              ALTER COLUMN public_id DROP DEFAULT;
        SQL);
    }
};
