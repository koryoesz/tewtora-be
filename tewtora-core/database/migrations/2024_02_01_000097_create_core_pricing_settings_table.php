<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Frontend's assessment budget/schedule step shows an indicative
 * per-session cost before any teacher is matched (a real teacher's own
 * price_per_session_minor is only known post-match). Confirmed platform-
 * wide and uniform per format for now, not per-teacher/subject — one row
 * per format, configurable without a deploy. Seeded with the frontend's
 * own placeholder values (₦5,000 one-to-one / ₦2,500 group) as the
 * current real rate — see database/seeders/PricingSettingsSeeder.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE core.pricing_settings (
              id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              format         VARCHAR(10) NOT NULL UNIQUE CHECK (format IN ('one_on_one','group')),
              rate_minor     BIGINT NOT NULL CHECK (rate_minor >= 0),
              currency_code  CHAR(3) NOT NULL DEFAULT 'NGN',
              updated_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.pricing_settings;');
    }
};
