<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-gap-analysis.md §4: the single biggest gap in the original
 * schema. A learner's recurring lesson arrangement (teacher, subject,
 * schedule, rate, sessions remaining) had no table of its own — Home,
 * Plans, Progress, Rebook, and Move-a-lesson (docs/api-contract.md §4-6)
 * all hang off this. A learner holds many plans concurrently (one per
 * subject/teacher) — `learner_profile_id` is deliberately not unique.
 *
 * learner_profile_id/teacher_id/subject_id are cross-schema references
 * into auth.* — plain indexed columns, not FKs, per the established
 * pattern (see core.sessions's migration for the fuller rationale).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE core.plans (
              id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id            CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              learner_profile_id   BIGINT UNSIGNED NOT NULL,
              teacher_id           BIGINT UNSIGNED NOT NULL,
              subject_id           BIGINT UNSIGNED NOT NULL,
              format               VARCHAR(10) NOT NULL CHECK (format IN ('one_on_one','group')),
              days                 JSON NOT NULL,
              time_of_day          TIME NOT NULL,
              status               VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','paused')),
              rate_minor           BIGINT NOT NULL CHECK (rate_minor >= 0),
              currency_code        CHAR(3) NOT NULL DEFAULT 'NGN',
              sessions_per_month   SMALLINT UNSIGNED NOT NULL,
              sessions_remaining   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              renews_at            DATETIME(6),
              reference            VARCHAR(30) NOT NULL UNIQUE,
              paid_to_date_minor   BIGINT NOT NULL DEFAULT 0 CHECK (paid_to_date_minor >= 0),
              created_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              CONSTRAINT chk_plans_renews_at_if_active CHECK (status = 'paused' OR renews_at IS NOT NULL),
              KEY ix_plans_learner (learner_profile_id, status),
              KEY ix_plans_teacher (teacher_id, status)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.plans;');
    }
};
