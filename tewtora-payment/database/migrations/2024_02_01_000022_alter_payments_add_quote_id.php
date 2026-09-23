<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Links a completed payment back to the quote it was charged against
 * (docs/api-contract.md §7) — same-database FK, unlike the cross-service
 * columns on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
              ADD COLUMN quote_id BIGINT UNSIGNED NULL AFTER public_id,
              ADD CONSTRAINT fk_payments_quote FOREIGN KEY (quote_id) REFERENCES payment_quotes(id) ON DELETE SET NULL,
              ADD KEY ix_payments_quote (quote_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
              DROP FOREIGN KEY fk_payments_quote,
              DROP KEY ix_payments_quote,
              DROP COLUMN quote_id;
        SQL);
    }
};
