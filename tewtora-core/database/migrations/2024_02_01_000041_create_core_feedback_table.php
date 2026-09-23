<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * teacher_id and learner_profile_id are cross-schema references into
 * auth.* — kept as plain indexed BIGINT UNSIGNED columns, not FKs. See the
 * note in 2024_02_01_000040_create_core_sessions_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE core.feedback (
              id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              session_id          BIGINT UNSIGNED NOT NULL UNIQUE,
              teacher_id          BIGINT UNSIGNED NOT NULL,
              learner_profile_id  BIGINT UNSIGNED NOT NULL,
              session_notes       TEXT NOT NULL,
              progress_rating     TINYINT UNSIGNED NOT NULL CHECK (progress_rating BETWEEN 1 AND 5),
              next_steps          TEXT,
              submitted_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_feedback_session FOREIGN KEY (session_id) REFERENCES core.sessions(id) ON DELETE RESTRICT,
              KEY ix_feedback_learner (learner_profile_id, submitted_at),
              KEY ix_feedback_teacher (teacher_id)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.feedback;');
    }
};
