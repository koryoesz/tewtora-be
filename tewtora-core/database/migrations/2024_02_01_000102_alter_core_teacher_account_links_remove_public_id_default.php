<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reverses 2024_02_01_000101. That migration was wrong: unlike
 * recommendation.matches (2024_02_01_000100, a genuinely independent
 * identity), core.teacher_account_links.public_id is a *mirror* of
 * auth.teachers.public_id (see 2024_02_01_000091's docblock — this table
 * exists so MessageThreadResource can expose a teacher's real public_id
 * without Core importing Auth's Teacher model directly). The only current
 * write site (SeedsAuthGraph::linkTeacherToCore) already copies it
 * explicitly, so the "doesn't have a default value" error 000101 chased was
 * never actually reachable in this codebase — it only appeared in a
 * throwaway comparison test run that had reverted that fixture back to an
 * older, already-fixed-elsewhere state.
 *
 * A DB-generated default here is actively worse than the NOT NULL failure
 * it replaces: every other public_id column in this codebase genuinely is
 * DB-generated ("public_id is DB-generated... call ->refresh()"), so a
 * future sync listener written by pattern-matching against the rest of the
 * codebase could plausibly omit public_id and silently get a *fresh,
 * unrelated* UUID instead of Auth's real one — corrupting the mirrored
 * value instead of erroring loudly. Restoring the NOT NULL failure forces
 * that listener to copy the source public_id explicitly, which is correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! CrossDatabaseSchema::columnHasDefault('core', 'teacher_account_links', 'public_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE core.teacher_account_links
              ALTER COLUMN public_id DROP DEFAULT;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE core.teacher_account_links
              ALTER COLUMN public_id SET DEFAULT (UUID());
        SQL);
    }
};
