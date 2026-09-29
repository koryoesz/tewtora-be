<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A policy deciding "is the authenticated account this match's teacher"
 * needs a teacher_id -> account_id link inside Recommendation's own
 * schema — without it, the policy would have to import Auth's Teacher
 * model directly, which is exactly the cross-domain query CLAUDE.md's hard
 * rule forbids. Mirrored via TeacherVerified the same way every other
 * field on this read model is (microservices-architecture.md §5); the
 * event catalog's payload list there is illustrative, not exhaustive —
 * account_id was always implicitly needed for the API to authorize
 * anything against this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while this column already exists from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::columnExists('recommendation', 'teacher_profile_view', 'account_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE recommendation.teacher_profile_view
              ADD COLUMN account_id BIGINT UNSIGNED NOT NULL AFTER public_id,
              ADD KEY ix_teacher_profile_view_account (account_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE recommendation.teacher_profile_view
              DROP KEY ix_teacher_profile_view_account,
              DROP COLUMN account_id;
        SQL);
    }
};
