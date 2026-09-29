<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Same gap as 2024_02_01_000100 (recommendation.matches), same cause:
 * 2024_02_01_000091 backfilled public_id but never gave the column a
 * DEFAULT, so any insert that doesn't explicitly set public_id hits error
 * 1364. `ALTER TABLE ... ALTER COLUMN col SET DEFAULT (expr)` is a pure
 * metadata change, so it avoids the ADD COLUMN + STATEMENT-binlog
 * restriction that ruled out setting the default in that migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (CrossDatabaseSchema::columnHasDefault('core', 'teacher_account_links', 'public_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE core.teacher_account_links
              ALTER COLUMN public_id SET DEFAULT (UUID());
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE core.teacher_account_links
              ALTER COLUMN public_id DROP DEFAULT;
        SQL);
    }
};
