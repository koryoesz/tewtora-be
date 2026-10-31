<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backs Laravel's password broker (config/auth.php `passwords.users.table`)
 * for POST /auth/forgot-password and /auth/reset-password. Owned by Auth,
 * like the accounts it resets. Laravel's own token-repository shape, not
 * this codebase's id/public_id key strategy: the table is keyed by email,
 * holds one short-lived row per account, and never leaves the API. `token`
 * is a hash (the broker hashes before storing); the plain token only ever
 * exists in the emailed link.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while auth.* still has its tables from before —
        // see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('auth', 'password_reset_tokens')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE auth.password_reset_tokens (
              email       VARCHAR(255) NOT NULL COLLATE utf8mb4_unicode_ci PRIMARY KEY,
              token       VARCHAR(255) NOT NULL,
              created_at  DATETIME(6)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS auth.password_reset_tokens;');
    }
};
