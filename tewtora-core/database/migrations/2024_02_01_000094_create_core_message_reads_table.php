<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-viewer read state — a thread can have multiple parties (owner
 * account, linked child login, teacher; or owner + any admin for a
 * support thread), each with their own unread count, so this can't live
 * as a single column on message_threads. A surrogate `id` PK rather than
 * a composite (thread_id, account_id) one — simpler with Eloquent,
 * uniqueness is still enforced by the key below.
 *
 * Deliberately only written by an explicit "mark read" call, never as a
 * side effect of GET /messages/threads/{id} — per the frontend's own
 * design note, a server render (or prefetch) marking messages read before
 * a user has actually seen them would be wrong for exactly the same
 * reason it was wrong client-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE core.message_reads (
              id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              thread_id    BIGINT UNSIGNED NOT NULL,
              account_id   BIGINT UNSIGNED NOT NULL,
              last_read_at DATETIME(6) NOT NULL,
              CONSTRAINT fk_message_reads_thread FOREIGN KEY (thread_id) REFERENCES core.message_threads(id) ON DELETE RESTRICT,
              UNIQUE KEY ux_message_reads_thread_account (thread_id, account_id)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.message_reads;');
    }
};
