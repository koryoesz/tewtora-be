<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * matches was created (2024_02_01_000032) without a public_id, following
 * database-design.md §3.4's original DDL. CLAUDE.md's hard rule — "No
 * exposing internal id (BIGINT) in any API response — public_id (UUID)
 * only" — makes that a real gap now that match-requests get an actual API
 * surface (docs/api-contract.md §9: `/match-requests/:id/accept`, etc.).
 *
 * `ADD COLUMN ... DEFAULT (UUID())` works fine inside CREATE TABLE but is
 * rejected by MySQL on an ALTER TABLE (error 1674: "Statement is unsafe
 * because it uses a system function that may return a different value on
 * the replica") when binlog_format is STATEMENT — only surfaced by
 * actually running this against real MySQL. Backfilled from PHP instead
 * (Str::uuid() per row, sent as a literal, not a SQL function call), which
 * sidesteps the replication-safety question entirely regardless of
 * binlog_format.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while this column already exists from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::columnExists('recommendation', 'matches', 'public_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE recommendation.matches
              ADD COLUMN public_id CHAR(36) NULL AFTER id;
        SQL);

        DB::table('recommendation.matches')->orderBy('id')->each(function ($row) {
            DB::table('recommendation.matches')
                ->where('id', $row->id)
                ->update(['public_id' => (string) Str::uuid()]);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE recommendation.matches
              MODIFY COLUMN public_id CHAR(36) NOT NULL,
              ADD UNIQUE KEY ux_matches_public_id (public_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE recommendation.matches
              DROP COLUMN public_id;
        SQL);
    }
};
