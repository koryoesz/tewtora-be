<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * max_group_size: docs/api-contract.md §3's Teacher.groupSize had no home —
 * `preferred_format` only says one_on_one/group/both, no numeric cap.
 *
 * new_matches_suspended_at: docs/api-contract.md §17's
 * `suspend-new-matches` is explicitly narrower than `verification_status`
 * or `deleted_at` — existing lessons must keep running, only new matching
 * stops. A separate nullable timestamp, not a new verification_status
 * value, so it composes independently of verification state. Recommendation
 * checks this column (via the usual event-synced read model) before
 * proposing a teacher in new matches — see docs/api-gap-analysis.md §17.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while auth.teachers already has these columns
        // from before — hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::columnExists('auth', 'teachers', 'max_group_size')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.teachers
              ADD COLUMN max_group_size SMALLINT UNSIGNED NULL AFTER preferred_format,
              ADD COLUMN new_matches_suspended_at DATETIME(6) NULL AFTER verification_status;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.teachers
              DROP COLUMN new_matches_suspended_at,
              DROP COLUMN max_group_size;
        SQL);
    }
};
