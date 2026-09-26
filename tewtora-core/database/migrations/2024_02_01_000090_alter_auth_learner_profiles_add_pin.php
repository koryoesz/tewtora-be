<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Frontend's child sign-in PIN screen (app/(learner)/children) needs a way
 * to know whether a child profile has a PIN set, without ever seeing the
 * PIN itself. Stored hashed (same Hash::make() convention as
 * accounts.password_hash), never returned raw — LearnerProfileResource
 * exposes only a derived `has_pin` boolean. Only meaningful on a `child`
 * profile_type row (an 'own' profile has no separate child login to
 * protect), but not CHECK-constrained to that — the application layer
 * (LearnerProfilePolicy/controller) is what enforces which profiles accept
 * a PIN, consistent with profile_type itself not being cross-validated
 * against linked_login_account_id beyond the existing self-login trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.learner_profiles
              ADD COLUMN pin_hash VARCHAR(255) NULL AFTER linked_login_account_id;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.learner_profiles
              DROP COLUMN pin_hash;
        SQL);
    }
};
