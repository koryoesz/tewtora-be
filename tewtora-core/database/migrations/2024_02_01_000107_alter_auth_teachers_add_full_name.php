<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/needed-endpoints-browse-matching.md §1: a browse list is useless if
 * every card is anonymous, and there is currently nowhere to put a
 * teacher's real name at all — auth.accounts only has
 * email/username/phone (an account-type-agnostic login identity, shared
 * by every role), and auth.learner_profiles.full_name is scoped to a
 * learner, not a teacher. Nullable, not backfilled: existing teacher rows
 * genuinely have no name on file yet, and a fabricated placeholder would
 * be worse than an honest null — TeacherResource/the browse endpoint
 * surface that as-is rather than inventing one. Teacher-editable via
 * PATCH /teachers/:id (UpdateTeacherProfileRequest), same as bio.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (CrossDatabaseSchema::columnExists('auth', 'teachers', 'full_name')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.teachers
              ADD COLUMN full_name VARCHAR(160) NULL AFTER account_id;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.teachers
              DROP COLUMN full_name;
        SQL);
    }
};
