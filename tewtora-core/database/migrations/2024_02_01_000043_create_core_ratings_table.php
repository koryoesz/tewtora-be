<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * rated_by_account_id and teacher_id are cross-schema references into
 * auth.* — kept as plain indexed BIGINT UNSIGNED columns, not FKs. See the
 * note in 2024_02_01_000040_create_core_sessions_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while core.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('core', 'ratings')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE core.ratings (
              id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              session_id           BIGINT UNSIGNED NOT NULL,
              rated_by_account_id  BIGINT UNSIGNED NOT NULL,
              teacher_id           BIGINT UNSIGNED NOT NULL,
              stars                TINYINT UNSIGNED NOT NULL CHECK (stars BETWEEN 1 AND 5),
              comment              TEXT,
              created_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_ratings_session FOREIGN KEY (session_id) REFERENCES core.sessions(id) ON DELETE RESTRICT,
              UNIQUE KEY ux_ratings_session_rater (session_id, rated_by_account_id),
              KEY ix_ratings_teacher (teacher_id, created_at)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.ratings;');
    }
};
