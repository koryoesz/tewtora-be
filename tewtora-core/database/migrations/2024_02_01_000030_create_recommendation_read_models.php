<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Local, event-synced projections of Auth data (microservices-architecture.md
 * §2 and §5). Recommendation never queries auth.learner_profiles or
 * auth.teachers directly — these tables are kept up to date by the
 * LearnerProfileUpdated / TeacherVerified event consumers and are the only
 * source Recommendation's matching logic reads from.
 *
 * `id` intentionally mirrors the source row's id in Auth (not a foreign key
 * across schemas — a mirrored identity, not a live join) so `matches` can
 * reference it as a normal same-schema foreign key. subject_ids/
 * curriculum_ids are JSON arrays — MySQL has no native array type.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while recommendation.* still has its tables from
        // before — hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('recommendation', 'learner_profile_view')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE recommendation.learner_profile_view (
              id             BIGINT UNSIGNED PRIMARY KEY,
              public_id      CHAR(36) NOT NULL,
              grade_level    VARCHAR(60) NOT NULL,
              curriculum_id  BIGINT UNSIGNED NOT NULL,
              budget_tier    VARCHAR(10) NOT NULL CHECK (budget_tier IN ('basic','standard','premium')),
              subject_ids    JSON NOT NULL DEFAULT (JSON_ARRAY()),
              synced_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB;

            CREATE TABLE recommendation.teacher_profile_view (
              id                    BIGINT UNSIGNED PRIMARY KEY,
              public_id             CHAR(36) NOT NULL,
              verification_status   VARCHAR(10) NOT NULL CHECK (verification_status IN ('pending','approved','rejected')),
              subject_ids           JSON NOT NULL DEFAULT (JSON_ARRAY()),
              curriculum_ids        JSON NOT NULL DEFAULT (JSON_ARRAY()),
              synced_at             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              KEY ix_teacher_profile_view_verification_status (verification_status)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS recommendation.teacher_profile_view;
            DROP TABLE IF EXISTS recommendation.learner_profile_view;
        SQL);
    }
};
