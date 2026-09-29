<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Auth's own outbox (microservices-architecture.md §4.2): publishes
 * LearnerProfileUpdated and TeacherVerified. Each domain that publishes
 * events owns its own outbox table — never a shared one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while auth.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('auth', 'outbox_events')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE auth.outbox_events (
              id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              aggregate_type  VARCHAR(50) NOT NULL,
              aggregate_id    BIGINT UNSIGNED NOT NULL,
              event_type      VARCHAR(100) NOT NULL,
              payload         JSON NOT NULL,
              created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              published_at    DATETIME(6),
              CONSTRAINT chk_published_after_created
                CHECK (published_at IS NULL OR published_at >= created_at),
              KEY ix_auth_outbox_unpublished (published_at, created_at)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS auth.outbox_events;');
    }
};
