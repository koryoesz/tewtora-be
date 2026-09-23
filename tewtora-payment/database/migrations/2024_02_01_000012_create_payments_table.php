<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies microservices-architecture.md §7.2 directly rather than
 * database-design.md §3.5's baseline: `provider_id` + a
 * (provider_id, provider_reference) unique constraint, not a single global
 * `provider_reference` unique index — that's the version the Payment
 * service actually implements, and the one that stops a retried webhook
 * from double-processing (§7.3). MySQL's unique index allows unlimited
 * NULL `provider_reference` rows per provider, same effect as the
 * Postgres partial-unique-index version.
 *
 * payer_account_id and session_id are cross-service references into Auth's
 * and Core's own databases — plain indexed BIGINT UNSIGNED columns, never
 * a live FK, since Payment is deployed and databased separately from day
 * one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payments (
              id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id           CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              payer_account_id    BIGINT UNSIGNED NOT NULL,
              session_id          BIGINT UNSIGNED,
              payment_method_id   BIGINT UNSIGNED NOT NULL,
              provider_id         BIGINT UNSIGNED NOT NULL,
              plan_type           VARCHAR(15) NOT NULL
                                    CHECK (plan_type IN ('per_session','weekly','monthly')),
              sessions_covered    SMALLINT UNSIGNED NOT NULL CHECK (sessions_covered > 0),
              amount_minor        BIGINT NOT NULL CHECK (amount_minor >= 0),
              currency_code       CHAR(3) NOT NULL DEFAULT 'NGN',
              status              VARCHAR(12) NOT NULL DEFAULT 'processing'
                                    CHECK (status IN ('processing','success','failed','pending')),
              provider_reference  VARCHAR(120),
              created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_payments_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE RESTRICT,
              CONSTRAINT fk_payments_provider FOREIGN KEY (provider_id) REFERENCES payment_providers(id) ON DELETE RESTRICT,
              KEY ix_payments_payer (payer_account_id, created_at),
              KEY ix_payments_session (session_id),
              UNIQUE KEY ux_payments_provider_ref_per_provider (provider_id, provider_reference)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS payments;');
    }
};
