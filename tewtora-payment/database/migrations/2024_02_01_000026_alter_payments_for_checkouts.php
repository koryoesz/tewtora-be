<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider checkouts (first user: Tewtora class enrolments, via Core's
 * POST /internal/checkouts call).
 *
 * - payment_method_id / plan_type / sessions_covered become nullable: a
 *   class fee isn't a per-session plan, and the method (card, transfer,
 *   USSD...) is chosen on Paystack's page and only known from the webhook
 *   (`channel`). Each MODIFY repeats the column's CHECK inline: MariaDB
 *   (production) drops an inline column CHECK on MODIFY otherwise, while on
 *   MySQL it's a harmless duplicate. NULL passes either check.
 * - purpose/purpose_ref: what the payment is for, as the calling service
 *   names it. Unique together, which is what makes POST /internal/checkouts
 *   idempotent: one payment per enrolment, however often Core asks.
 * - The Paystack provider row is inserted here (platform config, needed in
 *   every environment, production included).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'purpose')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
              MODIFY COLUMN payment_method_id BIGINT UNSIGNED NULL,
              MODIFY COLUMN plan_type VARCHAR(15) NULL CHECK (plan_type IN ('per_session','weekly','monthly')),
              MODIFY COLUMN sessions_covered SMALLINT UNSIGNED NULL CHECK (sessions_covered > 0),
              ADD COLUMN purpose VARCHAR(30) NULL AFTER payer_account_id,
              ADD COLUMN purpose_ref VARCHAR(64) NULL AFTER purpose,
              ADD COLUMN checkout_url VARCHAR(500) NULL AFTER provider_reference,
              ADD COLUMN channel VARCHAR(30) NULL AFTER checkout_url,
              ADD COLUMN failure_reason VARCHAR(255) NULL AFTER channel,
              ADD COLUMN paid_at DATETIME(6) NULL AFTER failure_reason,
              ADD COLUMN updated_at DATETIME(6) NULL ON UPDATE CURRENT_TIMESTAMP(6) AFTER created_at,
              ADD UNIQUE KEY ux_payments_purpose (purpose, purpose_ref);
        SQL);

        DB::table('payment_providers')->insertOrIgnore([
            'code' => 'paystack',
            'display_name' => 'Paystack',
            'webhook_secret_ref' => 'env:PAYSTACK_SECRET_KEY',
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
              DROP KEY ux_payments_purpose,
              DROP COLUMN updated_at,
              DROP COLUMN paid_at,
              DROP COLUMN failure_reason,
              DROP COLUMN channel,
              DROP COLUMN checkout_url,
              DROP COLUMN purpose_ref,
              DROP COLUMN purpose;
        SQL);
    }
};
