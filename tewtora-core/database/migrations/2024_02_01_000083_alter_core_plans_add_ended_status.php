<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §4: `DELETE /plans/:id` "ends the plan" but must
 * retain history — plans has no deleted_at (only accounts/learner_profiles/
 * teachers get soft deletes, per database-design.md §1.4's append-only
 * rule for everything else), so this needs a third status value, not a
 * row deletion.
 *
 * Also fixes chk_plans_renews_at_if_active: as originally written
 * (status = 'paused' OR renews_at IS NOT NULL), an 'ended' plan would
 * incorrectly still be required to carry a renews_at. Rewritten to only
 * require it for 'active'. That constraint was explicitly named in the
 * original migration, so it's referenced directly — only the status
 * IN-list CHECK (originally inline/unnamed on the column definition) needs
 * a dynamic lookup, and even then by column presence rather than by
 * matching the value list's literal text: MySQL normalizes
 * CHECK_CLAUSE with charset prefixes (_utf8mb4'active'), so a pattern
 * built from the plain SQL text wouldn't reliably match.
 */
return new class extends Migration
{
    public function up(): void
    {
        $statusCheck = $this->findStatusCheckConstraint();

        if ($statusCheck !== null) {
            DB::statement("ALTER TABLE core.plans DROP CONSTRAINT `{$statusCheck}`");
        }

        DB::statement(<<<'SQL'
            ALTER TABLE core.plans
              ADD CONSTRAINT chk_plans_status
              CHECK (status IN ('active','paused','ended'))
        SQL);

        DB::statement('ALTER TABLE core.plans DROP CONSTRAINT chk_plans_renews_at_if_active');
        DB::statement(<<<'SQL'
            ALTER TABLE core.plans
              ADD CONSTRAINT chk_plans_renews_at_if_active
              CHECK (status != 'active' OR renews_at IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE core.plans DROP CONSTRAINT chk_plans_renews_at_if_active');
        DB::statement(<<<'SQL'
            ALTER TABLE core.plans
              ADD CONSTRAINT chk_plans_renews_at_if_active
              CHECK (status = 'paused' OR renews_at IS NOT NULL)
        SQL);

        DB::statement('ALTER TABLE core.plans DROP CONSTRAINT chk_plans_status');
        DB::statement(<<<'SQL'
            ALTER TABLE core.plans
              ADD CONSTRAINT chk_plans_status_restored
              CHECK (status IN ('active','paused'))
        SQL);
    }

    /**
     * Same MariaDB quirk as 2024_02_01_000050_alter_auth_accounts_add_roles:
     * information_schema.CHECK_CONSTRAINTS reports a name for this inline
     * column-level CHECK that MariaDB's own ALTER TABLE ... DROP CONSTRAINT
     * then refuses (error 1091), while MySQL 8 accepts it. Read the name
     * straight out of SHOW CREATE TABLE instead — matched by which column
     * the CHECK references (status, but not renews_at) rather than by the
     * value list's literal text, since MySQL normalizes CHECK_CLAUSE with
     * charset prefixes (_utf8mb4'active') that wouldn't reliably match.
     *
     * SHOW CREATE TABLE wraps CHECK clauses in a doubled paren
     * (`CHECK ((\`status\` in (...)))`), so clauses are extracted with a
     * paren-balancing scan rather than a fixed-depth regex. Excludes
     * chk_plans_status by name so a retry after this migration partially
     * ran still finds the original rather than re-matching its successor.
     */
    private function findStatusCheckConstraint(): ?string
    {
        $row = DB::selectOne('SHOW CREATE TABLE core.plans');
        $ddl = $row?->{'Create Table'};

        if ($ddl === null) {
            return null;
        }

        foreach ($this->extractCheckConstraints($ddl) as $name => $clause) {
            if ($name !== 'chk_plans_status' && str_contains($clause, 'status') && ! str_contains($clause, 'renews_at')) {
                return $name;
            }
        }

        return null;
    }

    /** @return array<string, string> constraint name => CHECK clause body */
    private function extractCheckConstraints(string $ddl): array
    {
        $constraints = [];

        if (! preg_match_all('/CONSTRAINT `([^`]+)` CHECK \(/', $ddl, $matches, PREG_OFFSET_CAPTURE)) {
            return $constraints;
        }

        foreach ($matches[1] as [$name, $nameOffset]) {
            $start = $nameOffset + strlen($name) + strlen('` CHECK (');
            $depth = 1;
            $pos = $start;

            while ($depth > 0 && $pos < strlen($ddl)) {
                if ($ddl[$pos] === '(') {
                    $depth++;
                } elseif ($ddl[$pos] === ')') {
                    $depth--;
                }
                $pos++;
            }

            $constraints[$name] = substr($ddl, $start, $pos - $start - 1);
        }

        return $constraints;
    }
};
