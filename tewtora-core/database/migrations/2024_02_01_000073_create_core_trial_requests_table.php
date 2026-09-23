<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §3. Owned by Core rather than Recommendation:
 * accepting a trial request's side effect is "create a core.sessions row"
 * (is_trial = true), and Core already owns the session lifecycle — see
 * docs/api-gap-analysis.md §3 for why this mirrors the MatchAccepted
 * pattern instead of needing a new sync call from Recommendation.
 *
 * learner_profile_id/teacher_id are cross-schema references into auth.* —
 * plain indexed columns, not FKs. session_id (set once accepted) is a
 * same-schema FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE core.trial_requests (
              id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id           CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              learner_profile_id  BIGINT UNSIGNED NOT NULL,
              teacher_id          BIGINT UNSIGNED NOT NULL,
              session_id          BIGINT UNSIGNED,
              slot_starts_at      DATETIME(6) NOT NULL,
              duration_minutes    SMALLINT UNSIGNED NOT NULL,
              status              VARCHAR(10) NOT NULL DEFAULT 'pending'
                                    CHECK (status IN ('pending','accepted','declined','cancelled','expired')),
              expires_at          DATETIME(6) NOT NULL,
              created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_trial_requests_session FOREIGN KEY (session_id) REFERENCES core.sessions(id) ON DELETE SET NULL,
              KEY ix_trial_requests_learner (learner_profile_id, status),
              KEY ix_trial_requests_teacher (teacher_id, status)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.trial_requests;');
    }
};
