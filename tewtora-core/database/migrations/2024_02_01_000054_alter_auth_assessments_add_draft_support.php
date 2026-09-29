<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §2 needs a resumable, autosaved draft before submit
 * (`PUT /learners/:id/assessment` ... `POST .../submit`). The original
 * write-once assessments table required every answer up front. This adds a
 * `status` column and relaxes the answer columns to nullable-until-submit,
 * with "required at submit" enforced by `chk_learning_goals_present`'s
 * successor (conditioned on status) plus the application's Form Request —
 * see docs/api-gap-analysis.md §2 for why this was chosen over a separate
 * assessment_drafts table.
 *
 * The consent-required-if-minor trigger (2024_02_01_000014) also needs to
 * be re-scoped here: unscoped, it would now fire on every draft autosave of
 * a child profile before consent has ever been collected. It's rebuilt to
 * only enforce the rule when NEW.status = 'submitted'.
 *
 * chk_learning_goals_present_if_submitted explicitly checks
 * `learning_goals IS NOT NULL` before JSON_LENGTH(...) > 0 — a CHECK that
 * evaluates to NULL (which `JSON_LENGTH(NULL) > 0` does) is treated by
 * MySQL as *passing*, same standard-SQL three-valued-logic rule as
 * Postgres. Without the explicit NULL guard, a submitted row with
 * learning_goals left NULL would silently satisfy the constraint instead
 * of being rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while auth.assessments already has this column
        // from before — hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::columnExists('auth', 'assessments', 'status')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.assessments
              ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'draft'
                CHECK (status IN ('draft','submitted')) AFTER learner_profile_id,
              MODIFY COLUMN learning_goals JSON NULL,
              MODIFY COLUMN budget_tier VARCHAR(10) NULL,
              MODIFY COLUMN preferred_format VARCHAR(15) NULL,
              MODIFY COLUMN session_frequency VARCHAR(15) NULL,
              MODIFY COLUMN availability JSON NULL;

            ALTER TABLE auth.assessments
              DROP CONSTRAINT chk_learning_goals_present,
              ADD CONSTRAINT chk_learning_goals_present_if_submitted
                CHECK (status = 'draft' OR (learning_goals IS NOT NULL AND JSON_LENGTH(learning_goals) > 0));

            DROP TRIGGER auth.trg_assessments_consent_required_if_minor_insert;
            DROP TRIGGER auth.trg_assessments_consent_required_if_minor_update;

            CREATE TRIGGER auth.trg_assessments_consent_required_if_minor_insert
              BEFORE INSERT ON auth.assessments
              FOR EACH ROW
              BEGIN
                IF NEW.status = 'submitted' AND NEW.consent_given = false AND EXISTS (
                  SELECT 1 FROM auth.learner_profiles lp
                  WHERE lp.id = NEW.learner_profile_id AND lp.profile_type = 'child'
                ) THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consent_required_for_child_profile';
                END IF;
              END;

            CREATE TRIGGER auth.trg_assessments_consent_required_if_minor_update
              BEFORE UPDATE ON auth.assessments
              FOR EACH ROW
              BEGIN
                IF NEW.status = 'submitted' AND NEW.consent_given = false AND EXISTS (
                  SELECT 1 FROM auth.learner_profiles lp
                  WHERE lp.id = NEW.learner_profile_id AND lp.profile_type = 'child'
                ) THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consent_required_for_child_profile';
                END IF;
              END;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER auth.trg_assessments_consent_required_if_minor_insert;
            DROP TRIGGER auth.trg_assessments_consent_required_if_minor_update;

            CREATE TRIGGER auth.trg_assessments_consent_required_if_minor_insert
              BEFORE INSERT ON auth.assessments
              FOR EACH ROW
              BEGIN
                IF NEW.consent_given = false AND EXISTS (
                  SELECT 1 FROM auth.learner_profiles lp
                  WHERE lp.id = NEW.learner_profile_id AND lp.profile_type = 'child'
                ) THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consent_required_for_child_profile';
                END IF;
              END;

            CREATE TRIGGER auth.trg_assessments_consent_required_if_minor_update
              BEFORE UPDATE ON auth.assessments
              FOR EACH ROW
              BEGIN
                IF NEW.consent_given = false AND EXISTS (
                  SELECT 1 FROM auth.learner_profiles lp
                  WHERE lp.id = NEW.learner_profile_id AND lp.profile_type = 'child'
                ) THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consent_required_for_child_profile';
                END IF;
              END;

            ALTER TABLE auth.assessments
              DROP CONSTRAINT chk_learning_goals_present_if_submitted,
              ADD CONSTRAINT chk_learning_goals_present
                CHECK (JSON_LENGTH(learning_goals) > 0);

            ALTER TABLE auth.assessments
              MODIFY COLUMN learning_goals JSON NOT NULL,
              MODIFY COLUMN budget_tier VARCHAR(10) NOT NULL,
              MODIFY COLUMN preferred_format VARCHAR(15) NOT NULL,
              MODIFY COLUMN session_frequency VARCHAR(15) NOT NULL,
              MODIFY COLUMN availability JSON NOT NULL,
              DROP COLUMN status;
        SQL);
    }
};
