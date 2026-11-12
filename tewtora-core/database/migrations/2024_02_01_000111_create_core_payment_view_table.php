<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * core.payment_view: Core's local read model of the payments families make,
 * for the staff Payments page (tewtora-fe docs/needed-endpoints-admin-payments.md).
 * Payment owns the money (`payments` in its own database); this table holds
 * what Core already knows about a payment it started a checkout for, plus its
 * outcome from PaymentSucceeded/PaymentFailed. That keeps Payment's internal
 * API as narrow as it is, and makes search/sort across payer, learner and
 * class plain local SQL instead of a cross-service join.
 *
 * payment_id is Payment's public_id (the id staff see). payer_*,
 * learner_name and for_label are snapshots taken when the row is written: who
 * paid and for what, at the time. status uses the staff vocabulary
 * (paid/pending/failed/refunded), not Payment's. `refunded` is allowed now
 * so recording refunds later needs no migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (CrossDatabaseSchema::tableExists('core', 'payment_view')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE core.payment_view (
              id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              payment_id               CHAR(36) NOT NULL,
              purpose                  VARCHAR(30) NOT NULL,
              purpose_ref              VARCHAR(64) NOT NULL,
              payer_account_id         BIGINT UNSIGNED NOT NULL,
              payer_account_public_id  CHAR(36) NOT NULL,
              payer_name               VARCHAR(160),
              payer_email              VARCHAR(255),
              learner_profile_id       BIGINT UNSIGNED,
              learner_name             VARCHAR(120),
              for_label                VARCHAR(200) NOT NULL,
              reference                VARCHAR(120),
              amount_minor             BIGINT NOT NULL,
              currency_code            CHAR(3) NOT NULL,
              status                   VARCHAR(10) NOT NULL DEFAULT 'pending',
              channel                  VARCHAR(30),
              paid_at                  DATETIME(6),
              created_at               DATETIME(6) NOT NULL,
              updated_at               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              CONSTRAINT chk_payment_view_status CHECK (status IN ('paid','pending','failed','refunded')),
              CONSTRAINT chk_payment_view_purpose CHECK (purpose IN ('class_enrollment','plan')),
              CONSTRAINT chk_payment_view_amount CHECK (amount_minor >= 0),
              CONSTRAINT chk_payment_view_paid_at CHECK (status <> 'paid' OR paid_at IS NOT NULL),
              UNIQUE KEY ux_payment_view_payment (payment_id),
              UNIQUE KEY ux_payment_view_purpose (purpose, purpose_ref),
              KEY ix_payment_view_created (created_at),
              KEY ix_payment_view_status_created (status, created_at),
              KEY ix_payment_view_paid (paid_at),
              KEY ix_payment_view_payer (payer_account_id)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.payment_view;');
    }
};
