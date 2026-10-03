<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A parent needs to disable a child's own username+PIN login without
 * touching the profile itself — deliberately independent of
 * learner_profiles.deleted_at (archive/restore, 2024_02_01_000013): a
 * learner can in principle be archived AND sign-in-paused at the same
 * time, and un-archiving must not silently un-pause sign-in or vice versa.
 * Enforced in AuthSessionService::loginChild() — a correct PIN for a
 * paused profile still fails login.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while this column already exists from before —
        // see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::columnExists('auth', 'learner_profiles', 'sign_in_paused')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.learner_profiles
              ADD COLUMN sign_in_paused TINYINT(1) NOT NULL DEFAULT 0 AFTER pin_hash;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.learner_profiles
              DROP COLUMN sign_in_paused;
        SQL);
    }
};
