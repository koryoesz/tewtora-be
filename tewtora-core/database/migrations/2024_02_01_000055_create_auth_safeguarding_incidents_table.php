<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §17. Doesn't fit cleanly into any of the four
 * existing domains — leaning on Auth since the side effect that matters
 * most (suspending a teacher from new matches) is an Auth-owned column
 * (auth.teachers.new_matches_suspended_at). See docs/api-gap-analysis.md
 * §17 for the reasoning and the alternative considered.
 *
 * session_id/plan_id are cross-schema references into core.* — plain
 * indexed columns, not FKs, same pattern as everywhere else a domain
 * boundary is crossed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while auth.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('auth', 'safeguarding_incidents')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE auth.safeguarding_incidents (
              id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id             CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              learner_profile_id    BIGINT UNSIGNED NOT NULL,
              teacher_id            BIGINT UNSIGNED NOT NULL,
              session_id            BIGINT UNSIGNED,
              reported_by_account_id BIGINT UNSIGNED NOT NULL,
              severity              VARCHAR(10) NOT NULL CHECK (severity IN ('high','medium')),
              status                VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
              summary               TEXT NOT NULL,
              closed_note           TEXT,
              reported_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              closed_at             DATETIME(6),
              CONSTRAINT fk_safeguarding_incidents_learner FOREIGN KEY (learner_profile_id) REFERENCES auth.learner_profiles(id) ON DELETE RESTRICT,
              CONSTRAINT fk_safeguarding_incidents_teacher FOREIGN KEY (teacher_id) REFERENCES auth.teachers(id) ON DELETE RESTRICT,
              CONSTRAINT fk_safeguarding_incidents_reporter FOREIGN KEY (reported_by_account_id) REFERENCES auth.accounts(id) ON DELETE RESTRICT,
              CONSTRAINT chk_safeguarding_closed_note CHECK (status = 'open' OR closed_note IS NOT NULL),
              KEY ix_safeguarding_incidents_status (status, reported_at)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS auth.safeguarding_incidents;');
    }
};
