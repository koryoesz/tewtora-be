<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Teacher self-sign-up (POST /auth/register, account_type: teacher) only
 * collects email + name + password — years_experience, preferred_format
 * and rate_minor are filled in afterwards through the onboarding wizard
 * (PATCH /teachers/:id). Nullable rather than defaulted: a fabricated
 * 0-experience / NGN 0 rate would be indistinguishable from a real one,
 * where null honestly reads as "not filled in yet" — same reasoning as
 * full_name in 2024_02_01_000107. VerificationService::decide() refuses
 * to approve a teacher while any of these are still null, so an
 * incomplete profile can never reach the browse list.
 *
 * On MySQL 8 the original inline CHECKs (preferred_format IN (...),
 * rate_minor >= 0) are stored as separate named table constraints
 * (teachers_chk_N), so MODIFY COLUMN leaves them in place; a NULL passes
 * either one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while this change is already applied — see
        // CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::columnIsNullable('auth', 'teachers', 'rate_minor')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.teachers
              MODIFY COLUMN years_experience SMALLINT UNSIGNED NULL,
              MODIFY COLUMN preferred_format VARCHAR(15) NULL COLLATE utf8mb4_unicode_ci,
              MODIFY COLUMN rate_minor BIGINT NULL;
        SQL);
    }

    /** Fails if any teacher has signed up but not finished onboarding — fill or remove those rows first. */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE auth.teachers
              MODIFY COLUMN years_experience SMALLINT UNSIGNED NOT NULL,
              MODIFY COLUMN preferred_format VARCHAR(15) NOT NULL COLLATE utf8mb4_unicode_ci,
              MODIFY COLUMN rate_minor BIGINT NOT NULL;
        SQL);
    }
};
