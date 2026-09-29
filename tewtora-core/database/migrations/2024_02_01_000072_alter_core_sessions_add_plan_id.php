<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-gap-analysis.md §4: a session recurs under a plan once one
 * exists. `match_id` is kept as-is (the one-time origin of the first
 * session, historical), `plan_id` becomes the operative link for every
 * session a plan generates going forward — same-schema FK, unlike the
 * cross-schema columns elsewhere on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while core.sessions already has this column from
        // before — hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::columnExists('core', 'sessions', 'plan_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE core.sessions
              ADD COLUMN plan_id BIGINT UNSIGNED NULL AFTER match_id,
              ADD CONSTRAINT fk_sessions_plan FOREIGN KEY (plan_id) REFERENCES core.plans(id) ON DELETE SET NULL,
              ADD KEY ix_sessions_plan (plan_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE core.sessions
              DROP FOREIGN KEY fk_sessions_plan,
              DROP KEY ix_sessions_plan,
              DROP COLUMN plan_id;
        SQL);
    }
};
