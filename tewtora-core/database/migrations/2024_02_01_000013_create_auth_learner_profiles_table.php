<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * chk_child_has_no_self_login was originally a CHECK constraint, but MySQL
 * rejects that outright (error 3823: "Column ... cannot be used in a check
 * constraint ... needed in a foreign key constraint referential action")
 * — a CHECK can't reference a column that also has an ON DELETE SET NULL
 * foreign key action on it, since MySQL can't guarantee the CHECK still
 * holds after the FK nulls the column out. Only surfaced by actually
 * running this migration against real MySQL; linting/models never catch
 * it. Rebuilt as a BEFORE INSERT/UPDATE trigger instead — same pattern
 * already used for the consent-required-if-minor rule
 * (2024_02_01_000014).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE auth.learner_profiles (
              id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id                CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              owner_account_id         BIGINT UNSIGNED NOT NULL,
              linked_login_account_id  BIGINT UNSIGNED,
              profile_type             VARCHAR(10) NOT NULL CHECK (profile_type IN ('child','own')),
              full_name                VARCHAR(120) NOT NULL,
              date_of_birth            DATE,
              grade_level              VARCHAR(60) NOT NULL,
              curriculum_id            BIGINT UNSIGNED NOT NULL,
              created_at               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              deleted_at               DATETIME(6),
              CONSTRAINT fk_learner_profiles_owner FOREIGN KEY (owner_account_id) REFERENCES auth.accounts(id) ON DELETE RESTRICT,
              CONSTRAINT fk_learner_profiles_linked_login FOREIGN KEY (linked_login_account_id) REFERENCES auth.accounts(id) ON DELETE SET NULL,
              CONSTRAINT fk_learner_profiles_curriculum FOREIGN KEY (curriculum_id) REFERENCES auth.curricula(id) ON DELETE RESTRICT,
              KEY ix_learner_profiles_owner (owner_account_id),
              KEY ix_learner_profiles_linked_login (linked_login_account_id)
            ) ENGINE=InnoDB;

            CREATE TRIGGER auth.trg_learner_profiles_no_self_login_insert
              BEFORE INSERT ON auth.learner_profiles
              FOR EACH ROW
              BEGIN
                IF NEW.profile_type = 'own' AND NEW.linked_login_account_id IS NOT NULL THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'own_profile_cannot_have_linked_login';
                END IF;
              END;

            CREATE TRIGGER auth.trg_learner_profiles_no_self_login_update
              BEFORE UPDATE ON auth.learner_profiles
              FOR EACH ROW
              BEGIN
                IF NEW.profile_type = 'own' AND NEW.linked_login_account_id IS NOT NULL THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'own_profile_cannot_have_linked_login';
                END IF;
              END;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS auth.learner_profiles;
        SQL);
    }
};
