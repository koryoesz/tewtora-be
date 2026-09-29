<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Frontend's messaging request: one thread per plan (Mathematics-with-
 * Chinedu and Chemistry-with-Fatima stay separate, matching two separate
 * Plan records) rather than one undifferentiated learner<->teacher stream
 * — a paused/ended plan keeps its own conversation history attached to it.
 * Also covers the one "Tewtora support" case: a parent/independent-student
 * <-> staff thread with no plan and no teacher, flagged by is_support.
 * Group-plan threads (more than one family) are explicitly out of scope
 * for this pass — plan_id here always resolves to exactly one family.
 *
 * learner_profile_id/teacher_id are cross-schema references into auth.*,
 * denormalized from the plan at creation time rather than joined — same
 * pattern as core.plans/core.sessions. teacher_id is NULL for a support
 * thread (staff, not a teacher, is the other party).
 *
 * No deleted_at: "nothing here can be deleted" is a safeguarding/audit
 * property per the frontend's own design intent, not just UI copy — a
 * plan ending only stops new messages via the application layer
 * (Plan::status), it never removes the thread or its history.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while core.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('core', 'message_threads')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE core.message_threads (
              id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id            CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              plan_id              BIGINT UNSIGNED,
              learner_profile_id   BIGINT UNSIGNED NOT NULL,
              teacher_id           BIGINT UNSIGNED,
              is_support           BOOLEAN NOT NULL DEFAULT false,
              last_message_at      DATETIME(6),
              created_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_message_threads_plan FOREIGN KEY (plan_id) REFERENCES core.plans(id) ON DELETE RESTRICT,
              CONSTRAINT chk_message_threads_shape CHECK (
                (is_support = false AND plan_id IS NOT NULL AND teacher_id IS NOT NULL)
                OR (is_support = true AND plan_id IS NULL AND teacher_id IS NULL)
              ),
              UNIQUE KEY ux_message_threads_plan (plan_id),
              KEY ix_message_threads_learner (learner_profile_id),
              KEY ix_message_threads_teacher (teacher_id)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.message_threads;');
    }
};
