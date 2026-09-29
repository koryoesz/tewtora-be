<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Materialized rollup (database-design.md §6): refreshed by a background
 * job / trigger on core.feedback inserts, never written ad hoc from
 * multiple code paths. learner_profile_id is a cross-schema reference into
 * auth.learner_profiles — kept as a plain PK column, not a FK+CASCADE,
 * since a cross-schema ON DELETE CASCADE can't be relied on once this
 * schema becomes its own database.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while core.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('core', 'progress_reports')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE core.progress_reports (
              learner_profile_id  BIGINT UNSIGNED PRIMARY KEY,
              attendance_pct      DECIMAL(5,2) NOT NULL DEFAULT 0,
              goal_progress_pct   DECIMAL(5,2) NOT NULL DEFAULT 0,
              sessions_completed  INT UNSIGNED NOT NULL DEFAULT 0,
              updated_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.progress_reports;');
    }
};
