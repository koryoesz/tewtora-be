<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Core needs to authorize "does this account own this learner profile" /
 * "is this account this teacher" for plans/sessions/trial-requests/
 * feedback/move-requests — the same problem Recommendation already solved
 * with teacher_profile_view.account_id (2024_02_01_000081). Core had no
 * equivalent, which would otherwise force a policy to import Auth's
 * LearnerProfile/Teacher models directly — exactly what CLAUDE.md's hard
 * rule forbids. These are minimal read models, synced by the same
 * LearnerProfileUpdated/TeacherVerified events Recommendation already
 * consumes (microservices-architecture.md §5) — Core simply becomes a
 * second consumer of the same events, not a new integration.
 *
 * The sync listeners themselves are not built in this pass — flagged, not
 * silently skipped: until they exist, these tables need to be populated by
 * whatever seeds/onboards test data.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while core.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('core', 'learner_account_links')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE core.learner_account_links (
              learner_profile_id       BIGINT UNSIGNED PRIMARY KEY,
              owner_account_id         BIGINT UNSIGNED NOT NULL,
              linked_login_account_id  BIGINT UNSIGNED,
              synced_at                DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              KEY ix_learner_account_links_owner (owner_account_id),
              KEY ix_learner_account_links_linked_login (linked_login_account_id)
            ) ENGINE=InnoDB;

            CREATE TABLE core.teacher_account_links (
              teacher_id  BIGINT UNSIGNED PRIMARY KEY,
              account_id  BIGINT UNSIGNED NOT NULL,
              synced_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              KEY ix_teacher_account_links_account (account_id)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS core.teacher_account_links;
            DROP TABLE IF EXISTS core.learner_account_links;
        SQL);
    }
};
