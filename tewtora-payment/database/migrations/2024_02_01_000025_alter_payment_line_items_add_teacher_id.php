<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The teacher-facing earnings ledger (docs/api-contract.md §10) needs to
 * filter payment_line_items by teacher, but nothing in Payment's own
 * schema links a line item to a teacher — only session_id, which is a
 * cross-service reference into Core, and only Core knows which teacher
 * taught that session. Rather than a cross-service lookup, teacher_id is
 * denormalized onto the line item at creation time (from whatever
 * cross-service call/event created it), the same pattern payer_account_id
 * already uses on `payments`. Plain indexed column, never a FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_line_items
              ADD COLUMN teacher_id BIGINT UNSIGNED NOT NULL AFTER session_id,
              ADD KEY ix_payment_line_items_teacher (teacher_id, status);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_line_items
              DROP KEY ix_payment_line_items_teacher,
              DROP COLUMN teacher_id;
        SQL);
    }
};
