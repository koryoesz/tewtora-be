<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §5 — a genuine multi-party workflow, not a simple
 * PATCH: a small-group move needs one approval per affected family plus
 * the teacher, and nothing touches the plan's actual schedule until every
 * party has accepted. `kind` also carries 'renewal' since renewals surface
 * on the same teacher Schedule screen as moves (per the contract).
 *
 * party_account_id is a cross-schema reference into auth.accounts — plain
 * indexed column, not a FK. plan_id is same-schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while core.* still has its tables from before —
        // hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::tableExists('core', 'move_requests')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE core.move_requests (
              id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id              CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
              plan_id                BIGINT UNSIGNED NOT NULL,
              kind                   VARCHAR(10) NOT NULL CHECK (kind IN ('move','renewal')),
              route                  VARCHAR(20) CHECK (route IS NULL OR route IN ('move_learner','move_group','to_one_to_one')),
              reason                 TEXT NOT NULL,
              from_day               VARCHAR(10) NOT NULL,
              from_starts_at         TIME NOT NULL,
              to_day                 VARCHAR(10) NOT NULL,
              to_starts_at           TIME NOT NULL,
              outside_teacher_hours  BOOLEAN NOT NULL DEFAULT false,
              status                 VARCHAR(10) NOT NULL DEFAULT 'pending'
                                       CHECK (status IN ('pending','accepted','declined','withdrawn','expired')),
              expires_at             DATETIME(6) NOT NULL,
              gross_minor            BIGINT NOT NULL CHECK (gross_minor >= 0),
              created_at             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              CONSTRAINT fk_move_requests_plan FOREIGN KEY (plan_id) REFERENCES core.plans(id) ON DELETE RESTRICT,
              KEY ix_move_requests_plan (plan_id, status)
            ) ENGINE=InnoDB;

            CREATE TABLE core.move_approvals (
              id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              move_request_id   BIGINT UNSIGNED NOT NULL,
              party_account_id  BIGINT UNSIGNED NOT NULL,
              party_label       VARCHAR(120) NOT NULL,
              role              VARCHAR(10) NOT NULL CHECK (role IN ('teacher','parent')),
              state             VARCHAR(10) NOT NULL DEFAULT 'pending'
                                  CHECK (state IN ('pending','accepted','declined')),
              responded_at      DATETIME(6),
              CONSTRAINT fk_move_approvals_move_request FOREIGN KEY (move_request_id) REFERENCES core.move_requests(id) ON DELETE CASCADE,
              UNIQUE KEY ux_move_approvals_request_party (move_request_id, party_account_id),
              KEY ix_move_approvals_party (party_account_id, state)
            ) ENGINE=InnoDB;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS core.move_approvals;
            DROP TABLE IF EXISTS core.move_requests;
        SQL);
    }
};
