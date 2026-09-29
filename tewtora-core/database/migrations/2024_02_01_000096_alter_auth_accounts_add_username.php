<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Child sign-in: a child logs in with a username + PIN (verified against
 * auth.learner_profiles.pin_hash via the linked_login_account_id join,
 * not a new credential store), not email + password. `email` was
 * NOT NULL — makes it optional so a child account can be created without
 * one, matching the frontend's "email should not be compulsory when
 * creating a child account" ask. `username_live` mirrors `email_live`'s
 * soft-delete-aware uniqueness (deliberately, not fewer moving parts):
 * without it, a deleted account holding a username would permanently
 * block a new account from ever using it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while this column already exists from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::columnExists('auth', 'accounts', 'username')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.accounts
              MODIFY COLUMN email VARCHAR(255) NULL COLLATE utf8mb4_unicode_ci,
              ADD COLUMN username VARCHAR(30) NULL COLLATE utf8mb4_unicode_ci AFTER email,
              ADD COLUMN username_live VARCHAR(30) COLLATE utf8mb4_unicode_ci
                AS (IF(deleted_at IS NULL, username, NULL)) STORED AFTER username,
              ADD UNIQUE KEY ux_accounts_username_live (username_live);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.accounts
              DROP KEY ux_accounts_username_live,
              DROP COLUMN username_live,
              DROP COLUMN username,
              MODIFY COLUMN email VARCHAR(255) NOT NULL COLLATE utf8mb4_unicode_ci;
        SQL);
    }
};
