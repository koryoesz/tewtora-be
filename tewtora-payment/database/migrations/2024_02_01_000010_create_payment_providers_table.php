<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payment is its own service, its own database, from day one
 * (microservices-architecture.md §1.1) — narrowest network segment of the
 * four, isolated for compliance/blast-radius reasons (CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payment_providers (
              id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              code                VARCHAR(30) NOT NULL UNIQUE,
              display_name        VARCHAR(60) NOT NULL,
              webhook_secret_ref  VARCHAR(120) NOT NULL,
              is_active           BOOLEAN NOT NULL DEFAULT true
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS payment_providers;');
    }
};
