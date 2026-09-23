<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `email` relies on the connection's case-insensitive collation
 * (utf8mb4_unicode_ci) instead of Postgres's CITEXT. `email_live` is a
 * generated column standing in for Postgres's partial unique index
 * (`ux_accounts_email ... WHERE deleted_at IS NULL`) — see
 * database-design.md §1.8.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE auth.accounts (
              id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id       CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              email           VARCHAR(255) NOT NULL COLLATE utf8mb4_unicode_ci,
              email_live      VARCHAR(255) COLLATE utf8mb4_unicode_ci
                                AS (IF(deleted_at IS NULL, email, NULL)) STORED,
              phone           VARCHAR(20),
              password_hash   VARCHAR(255) NOT NULL,
              account_type    VARCHAR(20) NOT NULL
                                CHECK (account_type IN ('parent','independent_student','teacher')),
              email_verified_at DATETIME(6),
              created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              deleted_at      DATETIME(6),
              UNIQUE KEY ux_accounts_email_live (email_live),
              KEY ix_accounts_type (account_type)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS auth.accounts;');
    }
};
