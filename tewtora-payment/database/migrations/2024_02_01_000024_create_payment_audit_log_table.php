<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §16's stuck-money `resolve` action ("moves real
 * money... the response should make clear this cannot be undone from this
 * endpoint") needs its own audited trail. This mirrors tewtora-core's
 * auth.audit_log table but is a separate table here, not a cross-service
 * write into it — Payment is a fully separate service/database
 * (microservices-architecture.md §1.1), and no domain writes into another
 * service's tables even for something as small as logging. actor_account_id
 * is a cross-service reference into Auth's database (the admin's account)
 * — plain indexed column, not a FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payment_audit_log (
              id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              actor_account_id  BIGINT UNSIGNED NOT NULL,
              action            VARCHAR(20) NOT NULL
                                  CHECK (action IN ('viewed','approved','rejected','refunded','acted','closed')),
              subject_type      VARCHAR(50) NOT NULL,
              subject_id        VARCHAR(120) NOT NULL,
              reason            TEXT,
              before_state      JSON,
              after_state       JSON,
              occurred_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              KEY ix_payment_audit_log_subject (subject_type, subject_id, occurred_at),
              KEY ix_payment_audit_log_actor (actor_account_id, occurred_at)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS payment_audit_log;');
    }
};
