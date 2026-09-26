<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The new message_threads table needs a stable external id for "which
 * teacher is this thread with" without Core reaching into Auth's Teacher
 * model directly — core.teacher_account_links already exists for exactly
 * this ("is this account this teacher"), it just never needed to expose a
 * public_id externally before now. Mirrors learner_account_links'
 * equivalent migration (2024_02_01_000085), except this one backfills
 * explicitly rather than assuming an empty table: unlike that one (which
 * has only ever run before any rows existed), this table already has real
 * seeded rows in every environment this migration will actually hit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE core.teacher_account_links ADD COLUMN public_id CHAR(36) NULL AFTER teacher_id;');

        DB::table('core.teacher_account_links')->orderBy('teacher_id')->each(function ($row) {
            DB::table('core.teacher_account_links')
                ->where('teacher_id', $row->teacher_id)
                ->update(['public_id' => (string) Str::uuid()]);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE core.teacher_account_links
              MODIFY COLUMN public_id CHAR(36) NOT NULL,
              ADD UNIQUE KEY ux_teacher_account_links_public_id (public_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE core.teacher_account_links
              DROP KEY ux_teacher_account_links_public_id,
              DROP COLUMN public_id;
        SQL);
    }
};
