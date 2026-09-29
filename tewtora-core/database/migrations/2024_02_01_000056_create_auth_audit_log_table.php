<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * database-design.md §5 flagged this as required before launch and it was
 * never built. docs/api-contract.md §14-18 makes it load-bearing: every
 * write to learner_profiles/payments/teachers.verification_status, and
 * every admin GET, needs an entry here. Lives in `auth` rather than
 * per-schema because it's a cross-cutting concern shared by Auth,
 * Recommendation, and Core within this bundled deployable (AGENTS.md:
 * "Only app/Shared is exempt, and only for genuinely cross-cutting
 * concerns") — the model/trait live in app/Shared/Logging, not a specific
 * Domain folder. Payment is a fully separate service/database and gets its
 * own audit_log table (tewtora-payment's own migration) rather than a
 * cross-service write into this one.
 *
 * subject_type/subject_id are a polymorphic pointer (e.g. 'teacher', 123),
 * deliberately not a FK — the audited subject can be in any schema, or (for
 * a search, per the contract's §18 note) not a row at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while auth.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('auth', 'audit_log')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE auth.audit_log (
              id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              actor_account_id  BIGINT UNSIGNED NOT NULL,
              action            VARCHAR(20) NOT NULL
                                  CHECK (action IN ('viewed','acted_as','approved','rejected','refunded','suspended','acted','closed')),
              subject_type      VARCHAR(50) NOT NULL,
              subject_id        VARCHAR(120) NOT NULL,
              reason            TEXT,
              before_state      JSON,
              after_state       JSON,
              occurred_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_audit_log_actor FOREIGN KEY (actor_account_id) REFERENCES auth.accounts(id) ON DELETE RESTRICT,
              KEY ix_audit_log_subject (subject_type, subject_id, occurred_at),
              KEY ix_audit_log_actor (actor_account_id, occurred_at)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS auth.audit_log;');
    }
};
