<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * learner_profile_id, teacher_id, and match_id are cross-schema references
 * (into auth.* and recommendation.*). Per the CLAUDE.md hard rule — "no
 * domain queries another domain's Eloquent models directly... treat the
 * module boundary as if the network call already existed" — these are kept
 * as plain indexed BIGINT UNSIGNED columns, not FOREIGN KEY constraints, so
 * the constraint doesn't have to be dropped the day this schema becomes a
 * separate database.
 *
 * `status` includes 'pending' and 'confirmed' (the booking saga states from
 * microservices-architecture.md §4) alongside the base schema's five values.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE core.sessions (
              id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id           CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              match_id            BIGINT UNSIGNED,
              learner_profile_id  BIGINT UNSIGNED NOT NULL,
              teacher_id          BIGINT UNSIGNED NOT NULL,
              scheduled_at        DATETIME(6) NOT NULL,
              format              VARCHAR(10) NOT NULL CHECK (format IN ('one_on_one','group')),
              is_trial            BOOLEAN NOT NULL DEFAULT false,
              status              VARCHAR(15) NOT NULL DEFAULT 'pending'
                                    CHECK (status IN ('pending','confirmed','scheduled','in_progress','completed','no_show','cancelled')),
              created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              KEY ix_sessions_match (match_id),
              KEY ix_sessions_learner_scheduled (learner_profile_id, scheduled_at),
              KEY ix_sessions_teacher_scheduled (teacher_id, scheduled_at),
              KEY ix_sessions_status_scheduled (status, scheduled_at)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.sessions;');
    }
};
