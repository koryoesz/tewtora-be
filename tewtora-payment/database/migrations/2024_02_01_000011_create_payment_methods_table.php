<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payment_methods (
              id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              code          VARCHAR(40) NOT NULL UNIQUE,
              display_name  VARCHAR(80) NOT NULL,
              is_active     BOOLEAN NOT NULL DEFAULT true
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS payment_methods;');
    }
};
