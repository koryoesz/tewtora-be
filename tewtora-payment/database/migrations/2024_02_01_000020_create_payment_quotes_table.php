<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §7: `/checkout/quote` must return a server-computed
 * total the confirm screen trusts, then `/checkout/pay` redeems it by id —
 * "the frontend must never render a total it computed itself and trust
 * it." A real table rather than a signed token: it's auditable and
 * survives a slow client past a short TTL without losing the record of
 * what was quoted.
 *
 * payer_account_id/teacher_id are cross-service references into Auth's own
 * database — plain indexed columns, never a live FK, same as payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payment_quotes (
              id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id         CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              payer_account_id  BIGINT UNSIGNED NOT NULL,
              teacher_id        BIGINT UNSIGNED NOT NULL,
              plan_kind         VARCHAR(15) NOT NULL CHECK (plan_kind IN ('per_session','weekly','monthly')),
              session_count     SMALLINT UNSIGNED NOT NULL CHECK (session_count > 0),
              line_items        JSON NOT NULL,
              total_minor       BIGINT NOT NULL CHECK (total_minor >= 0),
              currency_code     CHAR(3) NOT NULL DEFAULT 'NGN',
              expires_at        DATETIME(6) NOT NULL,
              created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              KEY ix_payment_quotes_payer (payer_account_id, created_at)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS payment_quotes;');
    }
};
