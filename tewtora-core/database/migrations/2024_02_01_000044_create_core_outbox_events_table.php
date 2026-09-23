<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Core's own outbox (microservices-architecture.md §4.2): publishes
 * SessionCompleted to the Payment service (drives payout calculation).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE core.outbox_events (
              id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              aggregate_type  VARCHAR(50) NOT NULL,
              aggregate_id    BIGINT UNSIGNED NOT NULL,
              event_type      VARCHAR(100) NOT NULL,
              payload         JSON NOT NULL,
              created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              published_at    DATETIME(6),
              CONSTRAINT chk_published_after_created
                CHECK (published_at IS NULL OR published_at >= created_at),
              KEY ix_core_outbox_unpublished (published_at, created_at)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS core.outbox_events;');
    }
};
