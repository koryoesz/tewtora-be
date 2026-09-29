<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §11: "Save draft" must keep payment held; only a
 * `submitted` feedback row releases it. Adds `attendance` (missing
 * entirely before) and a status column, relaxing session_notes/
 * progress_rating to nullable-until-submitted the same way
 * 2024_02_01_000054 did for assessments — required-at-submit is enforced
 * by the CHECK below plus the Form Request layer, not a bare NOT NULL.
 * `UNIQUE(session_id)` still holds: one feedback row per session, draft or
 * final.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while core.feedback already has this column from
        // before — hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::columnExists('core', 'feedback', 'attendance')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE core.feedback
              ADD COLUMN attendance VARCHAR(15) NULL
                CHECK (attendance IS NULL OR attendance IN ('present','absent','late')) AFTER learner_profile_id,
              ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'draft'
                CHECK (status IN ('draft','submitted')) AFTER attendance,
              MODIFY COLUMN session_notes TEXT NULL,
              MODIFY COLUMN progress_rating TINYINT UNSIGNED NULL;

            ALTER TABLE core.feedback
              ADD CONSTRAINT chk_feedback_notes_present_if_submitted
                CHECK (status = 'draft' OR (session_notes IS NOT NULL AND session_notes != '')),
              ADD CONSTRAINT chk_feedback_rating_present_if_submitted
                CHECK (status = 'draft' OR progress_rating IS NOT NULL);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE core.feedback
              DROP CONSTRAINT chk_feedback_rating_present_if_submitted,
              DROP CONSTRAINT chk_feedback_notes_present_if_submitted,
              MODIFY COLUMN session_notes TEXT NOT NULL,
              MODIFY COLUMN progress_rating TINYINT UNSIGNED NOT NULL,
              DROP COLUMN status,
              DROP COLUMN attendance;
        SQL);
    }
};
