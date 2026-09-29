<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * database-design.md §7 already flagged this: "teacher_availability models
 * weekly recurring slots only; if the product needs date-specific
 * exceptions... that's an additive table, not a rework of this one."
 * docs/api-contract.md §13's `block-time-off` confirms it's needed now.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while auth.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('auth', 'teacher_time_off')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE auth.teacher_time_off (
              id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              teacher_id   BIGINT UNSIGNED NOT NULL,
              starts_at    DATETIME(6) NOT NULL,
              ends_at      DATETIME(6) NOT NULL,
              reason       VARCHAR(255),
              created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_teacher_time_off_teacher FOREIGN KEY (teacher_id) REFERENCES auth.teachers(id) ON DELETE CASCADE,
              CONSTRAINT chk_teacher_time_off_window CHECK (ends_at > starts_at),
              KEY ix_teacher_time_off_teacher (teacher_id, starts_at)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS auth.teacher_time_off;');
    }
};
