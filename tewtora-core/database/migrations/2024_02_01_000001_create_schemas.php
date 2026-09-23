<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Auth & Onboarding, Recommendation, and Main/Core are bundled into one
 * deployable unit but keep three separate schemas (docs/microservices-
 * architecture.md §1.1), so splitting them into independent services later
 * is a redeploy, not a redesign. Under MySQL a "schema" is a database, so
 * this is three databases on one instance, referenced everywhere else in
 * these migrations/models as `auth.table`, `recommendation.table`,
 * `core.table`. The connecting user needs CREATE privileges on all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE DATABASE IF NOT EXISTS auth
              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE DATABASE IF NOT EXISTS recommendation
              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE DATABASE IF NOT EXISTS core
              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP DATABASE IF EXISTS core;
            DROP DATABASE IF EXISTS recommendation;
            DROP DATABASE IF EXISTS auth;
        SQL);
    }
};
