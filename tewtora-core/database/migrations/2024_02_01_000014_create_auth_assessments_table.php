<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The consent-on-minor rule depends on another row (auth.learner_profiles)
 * so it can't be a same-row CHECK under MySQL either — implemented as a
 * BEFORE INSERT/UPDATE trigger, matching database-design.md §3.2's flagged
 * note. The equivalent application-layer guard is ConsentRequiredIfMinor
 * (backend-engineering-standards.md §9), enforced again at the Form
 * Request layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE auth.assessments (
              id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              learner_profile_id  BIGINT UNSIGNED NOT NULL,
              academic_challenges JSON,
              learning_goals      JSON NOT NULL,
              budget_tier         VARCHAR(10) NOT NULL CHECK (budget_tier IN ('basic','standard','premium')),
              preferred_format    VARCHAR(15) NOT NULL
                                    CHECK (preferred_format IN ('one_on_one','group','no_preference')),
              session_frequency   VARCHAR(15) NOT NULL
                                    CHECK (session_frequency IN ('weekly','twice_weekly','custom')),
              availability        JSON NOT NULL,
              consent_given       BOOLEAN NOT NULL DEFAULT false,
              submitted_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_assessments_learner FOREIGN KEY (learner_profile_id) REFERENCES auth.learner_profiles(id) ON DELETE RESTRICT,
              CONSTRAINT chk_learning_goals_present CHECK (JSON_LENGTH(learning_goals) > 0),
              KEY ix_assessments_learner (learner_profile_id, submitted_at)
            ) ENGINE=InnoDB;

            CREATE TRIGGER trg_assessments_consent_required_if_minor_insert
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

            CREATE TRIGGER trg_assessments_consent_required_if_minor_update
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

            CREATE TABLE auth.assessment_subjects (
              assessment_id  BIGINT UNSIGNED NOT NULL,
              subject_id     BIGINT UNSIGNED NOT NULL,
              PRIMARY KEY (assessment_id, subject_id),
              CONSTRAINT fk_assessment_subjects_assessment FOREIGN KEY (assessment_id) REFERENCES auth.assessments(id) ON DELETE CASCADE,
              CONSTRAINT fk_assessment_subjects_subject FOREIGN KEY (subject_id) REFERENCES auth.subjects(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS auth.assessment_subjects;
            DROP TRIGGER IF EXISTS auth.trg_assessments_consent_required_if_minor_update;
            DROP TRIGGER IF EXISTS auth.trg_assessments_consent_required_if_minor_insert;
            DROP TABLE IF EXISTS auth.assessments;
        SQL);
    }
};
