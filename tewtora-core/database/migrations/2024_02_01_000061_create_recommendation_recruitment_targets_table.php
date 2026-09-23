<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §15: `SupplyGap.recruitmentOpen` and
 * `/supply-gaps/:id/open-recruitment` pausing new bookings for that
 * subject/curriculum is real mutable state, not a computed read — the rest
 * of §15 (UnfilledAssessment, SupplyGap's counts) is a query over existing
 * tables, no schema needed there.
 *
 * subject_id/curriculum_id are cross-schema references into auth.* — plain
 * indexed columns, not FKs, per the established pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE recommendation.recruitment_targets (
              id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              subject_id       BIGINT UNSIGNED NOT NULL,
              curriculum_id    BIGINT UNSIGNED NOT NULL,
              bookings_paused  BOOLEAN NOT NULL DEFAULT false,
              opened_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              UNIQUE KEY ux_recruitment_targets_subject_curriculum (subject_id, curriculum_id)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS recommendation.recruitment_targets;');
    }
};
