<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-gap-analysis.md §7/§10: the product rule is "hold the full
 * amount, release one session's fee after that session is taught and fed
 * back on" — a single `payments.status` can't represent that per-session.
 * One row per session a `payments` row covers; the teacher-facing ledger
 * (docs/api-contract.md §10) and the admin stuck-money view (§16) both
 * project off this table rather than each keeping their own copy.
 *
 * Released by Core's feedback-submission event (the natural extension of
 * the already-planned SessionCompleted event in the outbox — see
 * docs/microservices-architecture.md §5) via an idempotent listener: only
 * transition 'held' -> 'released', never re-apply to an already-released
 * row, same idempotency discipline as the webhook handler.
 *
 * session_id is a cross-service reference into Core's own database — plain
 * indexed column, never a live FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payment_line_items (
              id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              payment_id    BIGINT UNSIGNED NOT NULL,
              session_id    BIGINT UNSIGNED,
              amount_minor  BIGINT NOT NULL CHECK (amount_minor >= 0),
              status        VARCHAR(10) NOT NULL DEFAULT 'held' CHECK (status IN ('held','released','refunded')),
              released_at   DATETIME(6),
              created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_payment_line_items_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
              CONSTRAINT chk_payment_line_items_released_at CHECK (status != 'released' OR released_at IS NOT NULL),
              KEY ix_payment_line_items_payment (payment_id),
              KEY ix_payment_line_items_session (session_id),
              KEY ix_payment_line_items_status (status)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS payment_line_items;');
    }
};
