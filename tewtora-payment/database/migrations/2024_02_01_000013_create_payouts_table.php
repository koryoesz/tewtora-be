<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * teacher_id is a cross-service reference into Auth's database — plain
 * indexed BIGINT UNSIGNED column, never a live FK. See the note in
 * 2024_02_01_000012_create_payments_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payouts (
              id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              teacher_id    BIGINT UNSIGNED NOT NULL,
              amount_minor  BIGINT NOT NULL CHECK (amount_minor >= 0),
              currency_code CHAR(3) NOT NULL DEFAULT 'NGN',
              method_id     BIGINT UNSIGNED NOT NULL,
              schedule      VARCHAR(10) NOT NULL CHECK (schedule IN ('weekly','biweekly','monthly')),
              period_start  DATE NOT NULL,
              period_end    DATE NOT NULL,
              status        VARCHAR(12) NOT NULL DEFAULT 'processing'
                              CHECK (status IN ('processing','paid','failed')),
              CONSTRAINT fk_payouts_method FOREIGN KEY (method_id) REFERENCES payment_methods(id) ON DELETE RESTRICT,
              CONSTRAINT chk_payout_period CHECK (period_end > period_start),
              KEY ix_payouts_teacher_period (teacher_id, period_start)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS payouts;');
    }
};
