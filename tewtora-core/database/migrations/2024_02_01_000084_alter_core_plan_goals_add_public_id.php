<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * plan_goals now has its own API resource (docs/api-contract.md §4) —
 * CLAUDE.md's "never expose id externally" rule needs a public_id like
 * everywhere else.
 *
 * See 2024_02_01_000080's docblock: `ADD COLUMN ... DEFAULT (UUID())` is
 * rejected on ALTER TABLE (error 1674, replication-unsafe under
 * binlog_format=STATEMENT) even though it works inside CREATE TABLE.
 * Backfilled from PHP instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE core.plan_goals
              ADD COLUMN public_id CHAR(36) NULL AFTER id;
        SQL);

        DB::table('core.plan_goals')->orderBy('id')->each(function ($row) {
            DB::table('core.plan_goals')
                ->where('id', $row->id)
                ->update(['public_id' => (string) Str::uuid()]);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE core.plan_goals
              MODIFY COLUMN public_id CHAR(36) NOT NULL,
              ADD UNIQUE KEY ux_plan_goals_public_id (public_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE core.plan_goals DROP COLUMN public_id;');
    }
};
