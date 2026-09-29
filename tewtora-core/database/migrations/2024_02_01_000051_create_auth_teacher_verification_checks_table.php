<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §3/§14 needs three independently tracked checks per
 * teacher, each with a 4-value state (pending|in-review|approved|rejected)
 * plus who/when reviewed it. The old auth.teachers columns
 * (id_verified_at/credentials_verified_at timestamps + demo_status's own
 * vocabulary) can't represent that — see docs/api-gap-analysis.md §3. This
 * table serves both the teacher-facing verification badge and the admin
 * verification queue (§14) from one source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while auth.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('auth', 'teacher_verification_checks')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE auth.teacher_verification_checks (
              id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              teacher_id            BIGINT UNSIGNED NOT NULL,
              kind                  VARCHAR(20) NOT NULL
                                      CHECK (kind IN ('government_id','credentials','teaching_demo')),
              state                 VARCHAR(15) NOT NULL DEFAULT 'pending'
                                      CHECK (state IN ('pending','in_review','approved','rejected')),
              evidence              TEXT,
              checked_by_account_id BIGINT UNSIGNED,
              checked_at            DATETIME(6),
              created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_teacher_verification_checks_teacher FOREIGN KEY (teacher_id) REFERENCES auth.teachers(id) ON DELETE CASCADE,
              CONSTRAINT fk_teacher_verification_checks_checked_by FOREIGN KEY (checked_by_account_id) REFERENCES auth.accounts(id) ON DELETE SET NULL,
              UNIQUE KEY ux_teacher_verification_checks_teacher_kind (teacher_id, kind)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS auth.teacher_verification_checks;');
    }
};
