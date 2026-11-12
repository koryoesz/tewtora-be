<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POST /class-enrollments/verify-payment looks an enrolment up by the
 * `?reference=` Paystack returns the payer with. payment_id already has a
 * unique index; this covers the provider_reference fallback so that lookup
 * never scans the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (CrossDatabaseSchema::indexExists('core', 'class_enrollments', 'ix_class_enrollments_provider_reference')) {
            return;
        }

        DB::unprepared('ALTER TABLE core.class_enrollments ADD KEY ix_class_enrollments_provider_reference (provider_reference);');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE core.class_enrollments DROP KEY ix_class_enrollments_provider_reference;');
    }
};
