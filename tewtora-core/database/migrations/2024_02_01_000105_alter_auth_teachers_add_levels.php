<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backs the new PATCH /teachers/:id profile-write endpoint
 * (UpdateTeacherProfileRequest). No existing field/enum represents "what
 * school-level bands this teacher teaches" at all (subjects/curricula
 * already have their own lookup tables + pivots, but levels has no
 * equivalent anywhere) — stored as a JSON string array here, the same
 * "small fixed enum, validated at the application layer, not a lookup
 * table" treatment auth.assessments already gives academic_challenges/
 * learning_goals, rather than standing up a third lookup+pivot pair for a
 * closed, rarely-changing vocabulary.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while this column already exists from before —
        // see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::columnExists('auth', 'teachers', 'levels')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.teachers
              ADD COLUMN levels JSON NULL AFTER preferred_format;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.teachers
              DROP COLUMN levels;
        SQL);
    }
};
