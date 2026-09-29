<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2024_02_01_000080 backfilled public_id but deliberately left the column
 * with no DEFAULT — `ADD COLUMN ... DEFAULT (UUID())` is rejected by MySQL
 * on an ALTER TABLE (error 1674) when binlog_format is STATEMENT, so the
 * value was backfilled row-by-row from PHP instead. That migration never
 * came back to give the column a default afterwards, so every insert that
 * doesn't explicitly set public_id (the norm everywhere else in this
 * codebase — see the "public_id is DB-generated" comment repeated at every
 * ::create() call) hits error 1364 ("doesn't have a default value").
 *
 * `ALTER TABLE ... ALTER COLUMN col SET DEFAULT (expr)` is a pure metadata
 * change — it doesn't touch existing rows, so it doesn't trip the same
 * statement-based-replication restriction ADD COLUMN does.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (CrossDatabaseSchema::columnHasDefault('recommendation', 'matches', 'public_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE recommendation.matches
              ALTER COLUMN public_id SET DEFAULT (UUID());
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE recommendation.matches
              ALTER COLUMN public_id DROP DEFAULT;
        SQL);
    }
};
