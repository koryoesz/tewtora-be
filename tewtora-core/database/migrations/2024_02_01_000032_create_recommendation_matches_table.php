<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE recommendation.matches (
              id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              learner_profile_id  BIGINT UNSIGNED NOT NULL,
              teacher_id          BIGINT UNSIGNED NOT NULL,
              match_reasoning     TEXT,
              status              VARCHAR(10) NOT NULL DEFAULT 'proposed'
                                    CHECK (status IN ('proposed','accepted','declined')),
              decline_reason      TEXT,
              created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_matches_learner FOREIGN KEY (learner_profile_id) REFERENCES recommendation.learner_profile_view(id) ON DELETE RESTRICT,
              CONSTRAINT fk_matches_teacher FOREIGN KEY (teacher_id) REFERENCES recommendation.teacher_profile_view(id) ON DELETE RESTRICT,
              KEY ix_matches_learner_status (learner_profile_id, status),
              KEY ix_matches_teacher_status (teacher_id, status)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS recommendation.matches;');
    }
};
