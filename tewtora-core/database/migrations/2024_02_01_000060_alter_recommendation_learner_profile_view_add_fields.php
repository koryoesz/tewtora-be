<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §9's AnonymisedBrief needs challenges/goals/
 * preferred_slots and a concrete budget kobo range — the read model
 * previously only carried grade_level/curriculum_id/budget_tier/
 * subject_ids from LearnerProfileUpdated. budget_tier is replaced by a
 * resolved min/max range (resolved in Auth at event-publish time, since
 * Recommendation must not look up a tier->range table in Auth's schema to
 * compute it itself) rather than kept alongside it, to avoid two sources of
 * truth for the same information drifting apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE recommendation.learner_profile_view
              ADD COLUMN challenges JSON NOT NULL DEFAULT (JSON_ARRAY()) AFTER curriculum_id,
              ADD COLUMN goals JSON NOT NULL DEFAULT (JSON_ARRAY()) AFTER challenges,
              ADD COLUMN preferred_slots JSON NOT NULL DEFAULT (JSON_ARRAY()) AFTER goals,
              ADD COLUMN budget_min_minor BIGINT NOT NULL DEFAULT 0 AFTER preferred_slots,
              ADD COLUMN budget_max_minor BIGINT NOT NULL DEFAULT 0 AFTER budget_min_minor,
              DROP COLUMN budget_tier;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE recommendation.learner_profile_view
              ADD COLUMN budget_tier VARCHAR(10) NOT NULL DEFAULT 'standard'
                CHECK (budget_tier IN ('basic','standard','premium')) AFTER curriculum_id,
              DROP COLUMN budget_max_minor,
              DROP COLUMN budget_min_minor,
              DROP COLUMN preferred_slots,
              DROP COLUMN goals,
              DROP COLUMN challenges;
        SQL);
    }
};
