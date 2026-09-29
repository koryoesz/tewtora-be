<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while auth.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('auth', 'teachers')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE auth.teachers (
              id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id               CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              account_id              BIGINT UNSIGNED NOT NULL UNIQUE,
              years_experience        SMALLINT UNSIGNED NOT NULL,
              bio                     TEXT,
              preferred_format        VARCHAR(15) NOT NULL
                                        CHECK (preferred_format IN ('one_on_one','group','both')),
              rate_minor              BIGINT NOT NULL CHECK (rate_minor >= 0),
              currency_code           CHAR(3) NOT NULL DEFAULT 'NGN',
              id_verified_at          DATETIME(6),
              credentials_verified_at DATETIME(6),
              demo_status             VARCHAR(15) NOT NULL DEFAULT 'not_submitted'
                                        CHECK (demo_status IN ('not_submitted','submitted','scheduled','completed')),
              verification_status     VARCHAR(10) NOT NULL DEFAULT 'pending'
                                        CHECK (verification_status IN ('pending','approved','rejected')),
              rating_avg              DECIMAL(3,2),
              created_at              DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at              DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              deleted_at              DATETIME(6),
              CONSTRAINT fk_teachers_account FOREIGN KEY (account_id) REFERENCES auth.accounts(id) ON DELETE RESTRICT,
              KEY ix_teachers_verification_status (verification_status)
            ) ENGINE=InnoDB;

            CREATE TABLE auth.teacher_subjects (
              teacher_id  BIGINT UNSIGNED NOT NULL,
              subject_id  BIGINT UNSIGNED NOT NULL,
              PRIMARY KEY (teacher_id, subject_id),
              CONSTRAINT fk_teacher_subjects_teacher FOREIGN KEY (teacher_id) REFERENCES auth.teachers(id) ON DELETE CASCADE,
              CONSTRAINT fk_teacher_subjects_subject FOREIGN KEY (subject_id) REFERENCES auth.subjects(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB;

            CREATE TABLE auth.teacher_curricula (
              teacher_id    BIGINT UNSIGNED NOT NULL,
              curriculum_id BIGINT UNSIGNED NOT NULL,
              PRIMARY KEY (teacher_id, curriculum_id),
              CONSTRAINT fk_teacher_curricula_teacher FOREIGN KEY (teacher_id) REFERENCES auth.teachers(id) ON DELETE CASCADE,
              CONSTRAINT fk_teacher_curricula_curriculum FOREIGN KEY (curriculum_id) REFERENCES auth.curricula(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB;

            CREATE TABLE auth.teacher_availability (
              id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              teacher_id   BIGINT UNSIGNED NOT NULL,
              day_of_week  TINYINT UNSIGNED NOT NULL CHECK (day_of_week BETWEEN 0 AND 6),
              start_time   TIME NOT NULL,
              end_time     TIME NOT NULL,
              CONSTRAINT fk_teacher_availability_teacher FOREIGN KEY (teacher_id) REFERENCES auth.teachers(id) ON DELETE CASCADE,
              CONSTRAINT chk_availability_window CHECK (end_time > start_time),
              KEY ix_teacher_availability_teacher (teacher_id, day_of_week)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS auth.teacher_availability;
            DROP TABLE IF EXISTS auth.teacher_curricula;
            DROP TABLE IF EXISTS auth.teacher_subjects;
            DROP TABLE IF EXISTS auth.teachers;
        SQL);
    }
};
