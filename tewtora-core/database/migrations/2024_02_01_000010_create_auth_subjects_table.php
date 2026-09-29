<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while auth.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('auth', 'subjects')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE auth.subjects (
              id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              code          VARCHAR(40) NOT NULL UNIQUE,
              display_name  VARCHAR(80) NOT NULL,
              is_active     BOOLEAN NOT NULL DEFAULT true
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS auth.subjects;');
    }
};
