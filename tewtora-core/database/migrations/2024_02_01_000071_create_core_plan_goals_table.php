<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §4's PlanGoal isn't derivable from anything else
 * stored — a table, not a JSON column, for consistency with how
 * progress_reports already treats rollups as first-class rows
 * (database-design.md §3.6), and so goals can be added/removed/reordered
 * independently without rewriting a blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE core.plan_goals (
              id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              plan_id  BIGINT UNSIGNED NOT NULL,
              label    VARCHAR(120) NOT NULL,
              pct      DECIMAL(5,2) NOT NULL DEFAULT 0 CHECK (pct BETWEEN 0 AND 100),
              CONSTRAINT fk_plan_goals_plan FOREIGN KEY (plan_id) REFERENCES core.plans(id) ON DELETE CASCADE,
              KEY ix_plan_goals_plan (plan_id)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.plan_goals;');
    }
};
