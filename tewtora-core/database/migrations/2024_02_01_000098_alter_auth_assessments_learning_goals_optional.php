<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Product decision: a learning goal is no longer required to submit an
 * assessment — drops chk_learning_goals_present_if_submitted entirely
 * rather than loosening its condition, since "optional" here means there's
 * no combination of status/learning_goals this table should reject anymore.
 * learning_goals itself stays nullable JSON (already the case since
 * 2024_02_01_000054) — this migration only removes the submit-time
 * requirement, not the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while this constraint is already gone — see
        // CrossDatabaseSchema's docblock.
        if (! CrossDatabaseSchema::constraintExists('auth', 'assessments', 'chk_learning_goals_present_if_submitted')) {
            return;
        }

        DB::statement('ALTER TABLE auth.assessments DROP CONSTRAINT chk_learning_goals_present_if_submitted');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE auth.assessments
              ADD CONSTRAINT chk_learning_goals_present_if_submitted
              CHECK (status = 'draft' OR (learning_goals IS NOT NULL AND JSON_LENGTH(learning_goals) > 0))
        SQL);
    }
};
