<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * core.trial_requests tracked `status` but never *when* the teacher
 * responded — a parent asking "did the teacher accept, and when" had no
 * way to answer the second half at all, even once a GET endpoint existed
 * to answer the first. Set only by TrialRequestService::accept()/decline()
 * (the teacher's own response), not cancel() — cancelling is the
 * requester's own action, not something to represent as "the teacher
 * responded".
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while this column already exists from before —
        // see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::columnExists('core', 'trial_requests', 'responded_at')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE core.trial_requests
              ADD COLUMN responded_at DATETIME(6) NULL AFTER status;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE core.trial_requests
              DROP COLUMN responded_at;
        SQL);
    }
};
