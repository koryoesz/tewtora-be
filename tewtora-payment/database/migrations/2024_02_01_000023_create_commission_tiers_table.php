<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §10's TeacherCommissionInfo (`standardRate: 0.15`,
 * `reducedRate: 0.12` at 100 lifetime sessions) — nothing anywhere records
 * a platform commission percentage today. A lookup table rather than
 * hardcoded constants, following the same "domains that will genuinely
 * grow/change get a table" rule as subjects/curricula/payment_methods
 * (database-design.md §1.6) — a rate change or a new tier is a new/updated
 * row, not a deploy.
 *
 * `completed_sessions_lifetime` per teacher (needed to pick a tier) is
 * computed by reading Core's session/feedback counts, not stored here —
 * this table is purely the rate config.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE commission_tiers (
              id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              rate_type           VARCHAR(10) NOT NULL UNIQUE CHECK (rate_type IN ('standard','reduced')),
              rate                DECIMAL(4,3) NOT NULL CHECK (rate >= 0 AND rate <= 1),
              threshold_sessions  INT UNSIGNED
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS commission_tiers;');
    }
};
