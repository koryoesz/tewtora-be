<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tewtora classes: courses Tewtora runs itself on a fixed timetable (owned by
 * Main/Core — microservices-architecture.md §2). Shaped after the frontend's
 * contract, tewtora-fe docs/needed-endpoints-tewtora-classes.md.
 *
 * - tewtora_classes: the course. subject_id/curriculum_id/teacher_id/
 *   teacher_account_id/created_by_account_id are cross-schema identities into
 *   auth.*, so plain indexed columns, not FKs — same as core.plans.
 *   `status` is the admin lifecycle (draft/published/cancelled/completed);
 *   the active/upcoming/completed phase families see is computed from the
 *   sessions, never stored. starts_at/ends_at are denormalised from the
 *   sessions (first start, last end) so catalogue filtering needs no join.
 *   price_minor = 0 is a free class. capacity NULL = no cap. image_path is a
 *   path on the class-images disk, never a URL; `art` picks one of the
 *   frontend's built-in illustrations when there's no image.
 *   rating_avg/rating_count roll up class_ratings (kept in step by
 *   ClassRatingService), so the catalogue reads them without aggregating.
 * - tewtora_class_sessions: the class days. day_number is 1..N in date order,
 *   rewritten whenever the schedule changes. meeting_link is that day's link,
 *   overriding the class-wide one; either may be null until posted. CASCADE:
 *   a day means nothing without its class.
 * - class_enrollments: one learner's seat. `active_key` is a generated column
 *   standing in for a partial unique index (same technique as
 *   accounts.email_live): at most one pending or confirmed enrolment per
 *   learner per class. Money is snapshotted at enrolment, so a later price
 *   edit can't change what someone was charged. payment_id is the Payment
 *   service's public_id. paid_at is separate from status: a payment can land
 *   after a hold expired or a class was cancelled and must still be recorded.
 * - class_ratings: one 1-5 rating per paying account per class, editable.
 *
 * Every CHECK is a named table constraint, not inline on a column, so a
 * later ALTER ... MODIFY on MariaDB can't silently drop it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while core.* still has these tables — see
        // CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('core', 'tewtora_classes')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE core.tewtora_classes (
              id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id             CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              title                 VARCHAR(160) NOT NULL,
              description           TEXT,
              subject_id            BIGINT UNSIGNED,
              curriculum_id         BIGINT UNSIGNED,
              level                 VARCHAR(40),
              format                VARCHAR(12) NOT NULL DEFAULT 'small_group',
              art                   VARCHAR(12),
              teacher_id            BIGINT UNSIGNED,
              teacher_account_id    BIGINT UNSIGNED,
              price_minor           BIGINT NOT NULL DEFAULT 0,
              currency_code         CHAR(3) NOT NULL DEFAULT 'NGN',
              capacity              SMALLINT UNSIGNED,
              meeting_link          VARCHAR(500),
              image_path            VARCHAR(255),
              status                VARCHAR(12) NOT NULL DEFAULT 'draft',
              starts_at             DATETIME(6),
              ends_at               DATETIME(6),
              rating_avg            DECIMAL(3,2),
              rating_count          INT UNSIGNED NOT NULL DEFAULT 0,
              published_at          DATETIME(6),
              cancelled_at          DATETIME(6),
              cancellation_reason   TEXT,
              completed_at          DATETIME(6),
              created_by_account_id BIGINT UNSIGNED NOT NULL,
              created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              CONSTRAINT chk_tewtora_classes_price CHECK (price_minor >= 0),
              CONSTRAINT chk_tewtora_classes_capacity CHECK (capacity IS NULL OR capacity > 0),
              CONSTRAINT chk_tewtora_classes_status CHECK (status IN ('draft','published','cancelled','completed')),
              CONSTRAINT chk_tewtora_classes_format CHECK (format IN ('small_group','live_class')),
              CONSTRAINT chk_tewtora_classes_art CHECK (art IS NULL OR art IN ('maths','english','science','coding')),
              CONSTRAINT chk_tewtora_classes_window CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at),
              CONSTRAINT chk_tewtora_classes_rating CHECK (rating_avg IS NULL OR (rating_avg >= 1 AND rating_avg <= 5)),
              KEY ix_tewtora_classes_status_window (status, starts_at, ends_at),
              KEY ix_tewtora_classes_teacher (teacher_id),
              KEY ix_tewtora_classes_subject (subject_id),
              KEY ix_tewtora_classes_curriculum (curriculum_id)
            ) ENGINE=InnoDB;

            CREATE TABLE core.tewtora_class_sessions (
              id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id         CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              tewtora_class_id  BIGINT UNSIGNED NOT NULL,
              day_number        SMALLINT UNSIGNED NOT NULL,
              starts_at         DATETIME(6) NOT NULL,
              ends_at           DATETIME(6) NOT NULL,
              meeting_link      VARCHAR(500),
              created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_tewtora_class_sessions_class FOREIGN KEY (tewtora_class_id) REFERENCES core.tewtora_classes(id) ON DELETE CASCADE,
              CONSTRAINT chk_tewtora_class_sessions_window CHECK (ends_at > starts_at),
              CONSTRAINT chk_tewtora_class_sessions_day CHECK (day_number > 0),
              UNIQUE KEY ux_tewtora_class_sessions_day (tewtora_class_id, day_number),
              KEY ix_tewtora_class_sessions_starts (starts_at)
            ) ENGINE=InnoDB;

            CREATE TABLE core.class_enrollments (
              id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id           CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              tewtora_class_id    BIGINT UNSIGNED NOT NULL,
              learner_profile_id  BIGINT UNSIGNED NOT NULL,
              payer_account_id    BIGINT UNSIGNED NOT NULL,
              status              VARCHAR(16) NOT NULL DEFAULT 'pending_payment',
              amount_minor        BIGINT NOT NULL,
              currency_code       CHAR(3) NOT NULL,
              payment_id          CHAR(36),
              provider_reference  VARCHAR(120),
              checkout_url        VARCHAR(500),
              hold_expires_at     DATETIME(6),
              paid_at             DATETIME(6),
              confirmed_at        DATETIME(6),
              expired_at          DATETIME(6),
              cancelled_at        DATETIME(6),
              active_key          VARCHAR(48) AS (
                                    IF(status IN ('pending_payment','confirmed'),
                                       CONCAT(tewtora_class_id, ':', learner_profile_id), NULL)
                                  ) STORED,
              created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_class_enrollments_class FOREIGN KEY (tewtora_class_id) REFERENCES core.tewtora_classes(id) ON DELETE RESTRICT,
              CONSTRAINT chk_class_enrollments_status CHECK (status IN ('pending_payment','confirmed','expired','cancelled')),
              CONSTRAINT chk_class_enrollments_amount CHECK (amount_minor >= 0),
              CONSTRAINT chk_class_enrollments_confirmed_at CHECK (status <> 'confirmed' OR confirmed_at IS NOT NULL),
              UNIQUE KEY ux_class_enrollments_active (active_key),
              UNIQUE KEY ux_class_enrollments_payment (payment_id),
              KEY ix_class_enrollments_class_status (tewtora_class_id, status),
              KEY ix_class_enrollments_payer (payer_account_id, created_at),
              KEY ix_class_enrollments_learner (learner_profile_id),
              KEY ix_class_enrollments_hold (status, hold_expires_at)
            ) ENGINE=InnoDB;

            CREATE TABLE core.class_ratings (
              id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id         CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              tewtora_class_id  BIGINT UNSIGNED NOT NULL,
              rater_account_id  BIGINT UNSIGNED NOT NULL,
              rating            TINYINT UNSIGNED NOT NULL,
              comment           TEXT,
              created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_class_ratings_class FOREIGN KEY (tewtora_class_id) REFERENCES core.tewtora_classes(id) ON DELETE RESTRICT,
              CONSTRAINT chk_class_ratings_rating CHECK (rating BETWEEN 1 AND 5),
              UNIQUE KEY ux_class_ratings_rater (tewtora_class_id, rater_account_id)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS core.class_ratings;
            DROP TABLE IF EXISTS core.class_enrollments;
            DROP TABLE IF EXISTS core.tewtora_class_sessions;
            DROP TABLE IF EXISTS core.tewtora_classes;
        SQL);
    }
};
