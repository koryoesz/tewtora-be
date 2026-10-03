<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/needed-endpoints-trial-requests.md: extends POST
 * /trial-requests/:id/respond to cover a decline reason, a counter-offered
 * alternate time, and the family responding specifically to that countered
 * time (not the original slot).
 *
 * decline_reason is deliberately just the fixed-enum code, not either of
 * its two wordings — TrialDeclineReason maps the code to a teacher-facing
 * and a family-facing sentence at read time (TrialRequestResource), so the
 * two wordings live in one place in code rather than being duplicated (and
 * able to drift) per row.
 *
 * countered_starts_at is a separate column from slot_starts_at, not an
 * overwrite of it — the state model needs to tell "responding to the
 * original slot" apart from "responding to a countered slot"
 * (TrialRequestService::accept/decline operate against whichever is
 * current for the request's status), and the original ask should stay
 * visible even after a counter is made.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! CrossDatabaseSchema::columnExists('core', 'trial_requests', 'decline_reason')) {
            DB::unprepared(<<<'SQL'
                ALTER TABLE core.trial_requests
                  ADD COLUMN decline_reason VARCHAR(10) NULL AFTER status,
                  ADD COLUMN countered_starts_at DATETIME(6) NULL AFTER slot_starts_at;
            SQL);
        }

        if (! CrossDatabaseSchema::constraintExists('core', 'trial_requests', 'chk_trial_requests_decline_reason')) {
            DB::statement(<<<'SQL'
                ALTER TABLE core.trial_requests
                  ADD CONSTRAINT chk_trial_requests_decline_reason
                  CHECK (decline_reason IS NULL OR decline_reason IN ('full','level','budget','other'))
            SQL);
        }

        $statusCheck = $this->findStatusCheckConstraint();

        if ($statusCheck !== null) {
            DB::statement("ALTER TABLE core.trial_requests DROP CONSTRAINT `{$statusCheck}`");
        }

        if (! CrossDatabaseSchema::constraintExists('core', 'trial_requests', 'chk_trial_requests_status')) {
            DB::statement(<<<'SQL'
                ALTER TABLE core.trial_requests
                  ADD CONSTRAINT chk_trial_requests_status
                  CHECK (status IN ('pending','countered','accepted','declined','cancelled','expired'))
            SQL);
        }
    }

    public function down(): void
    {
        if (CrossDatabaseSchema::constraintExists('core', 'trial_requests', 'chk_trial_requests_status')) {
            DB::statement('ALTER TABLE core.trial_requests DROP CONSTRAINT chk_trial_requests_status');
            DB::statement(<<<'SQL'
                ALTER TABLE core.trial_requests
                  ADD CONSTRAINT chk_trial_requests_status_restored
                  CHECK (status IN ('pending','accepted','declined','cancelled','expired'))
            SQL);
        }

        if (CrossDatabaseSchema::constraintExists('core', 'trial_requests', 'chk_trial_requests_decline_reason')) {
            DB::statement('ALTER TABLE core.trial_requests DROP CONSTRAINT chk_trial_requests_decline_reason');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE core.trial_requests
              DROP COLUMN decline_reason,
              DROP COLUMN countered_starts_at;
        SQL);
    }

    /**
     * The original CHECK on `status` was inline on the column definition
     * (2024_02_01_000073), which MySQL names automatically
     * (`trial_requests_chk_1`, confirmed via SHOW CREATE TABLE) rather than
     * leaving unnamed — found by which column it references, same approach
     * 2024_02_01_000083_alter_core_plans_add_ended_status uses, so a replay
     * after this migration already ran finds nothing (excluded by name)
     * instead of trying to re-drop its own successor.
     */
    private function findStatusCheckConstraint(): ?string
    {
        $row = DB::selectOne('SHOW CREATE TABLE core.trial_requests');
        $ddl = $row?->{'Create Table'};

        if ($ddl === null) {
            return null;
        }

        foreach ($this->extractCheckConstraints($ddl) as $name => $clause) {
            if ($name !== 'chk_trial_requests_status' && $name !== 'chk_trial_requests_decline_reason' && str_contains($clause, 'status') && ! str_contains($clause, 'decline_reason')) {
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
