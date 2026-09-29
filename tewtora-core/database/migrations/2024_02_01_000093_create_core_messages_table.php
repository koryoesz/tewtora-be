<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * sender_account_id/sender_role are both cross-schema references into
 * auth.accounts — sender_role is denormalized at write time (captured
 * from the authenticated request, which already knows it) rather than
 * joined later, since MessageResource must never reach into Auth's
 * Account model just to label who sent a message.
 *
 * redacted records whether MessageRedactor changed the body on the way
 * in — kept alongside the already-redacted body (never the raw original;
 * an unredacted copy of a phone number/email is exactly what this
 * feature exists to prevent from persisting anywhere) so the UI can
 * show "this message was edited to remove contact details" without
 * guessing from the text.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while core.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('core', 'messages')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE core.messages (
              id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id           CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              thread_id           BIGINT UNSIGNED NOT NULL,
              sender_account_id   BIGINT UNSIGNED NOT NULL,
              sender_role         VARCHAR(20) NOT NULL CHECK (sender_role IN ('parent','independent_student','child','teacher','admin')),
              body                TEXT NOT NULL,
              redacted            BOOLEAN NOT NULL DEFAULT false,
              created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_messages_thread FOREIGN KEY (thread_id) REFERENCES core.message_threads(id) ON DELETE RESTRICT,
              KEY ix_messages_thread (thread_id, created_at)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.messages;');
    }
};
