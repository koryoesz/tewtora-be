<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Routes like GET /learners/:id/plans arrive with the learner's public_id
 * (docs/api-contract.md §4), but core.learner_account_links
 * (2024_02_01_000082) only mirrored the numeric id — Core had no way to
 * resolve a public_id to that id without querying Auth's LearnerProfile
 * model directly. Mirrors public_id the same way teacher_profile_view and
 * matches both do.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while this column already exists from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::columnExists('core', 'learner_account_links', 'public_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE core.learner_account_links
              ADD COLUMN public_id CHAR(36) NOT NULL AFTER learner_profile_id,
              ADD UNIQUE KEY ux_learner_account_links_public_id (public_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE core.learner_account_links
              DROP KEY ux_learner_account_links_public_id,
              DROP COLUMN public_id;
        SQL);
    }
};
