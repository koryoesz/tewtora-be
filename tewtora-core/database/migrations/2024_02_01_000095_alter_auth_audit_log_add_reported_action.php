<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SafeguardingService::reportConcern() (called from Core's message-
 * reporting flow) audits its own action as 'reported' — not in the
 * original fixed action list. Same MariaDB/MySQL CHECK-rewrite pattern as
 * 2024_02_01_000050_alter_auth_accounts_add_roles: this inline
 * column-level CHECK has no name information_schema.CHECK_CONSTRAINTS
 * reports that MariaDB's DROP CONSTRAINT will actually accept, so the
 * real name is read straight out of SHOW CREATE TABLE instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while auth.audit_log already has this constraint
        // from before — hit for real; see CrossDatabaseSchema's docblock.
        if (CrossDatabaseSchema::constraintExists('auth', 'audit_log', 'chk_audit_log_action')) {
            return;
        }

        $constraint = $this->findActionCheckConstraint();

        if ($constraint !== null) {
            DB::statement("ALTER TABLE auth.audit_log DROP CONSTRAINT `{$constraint}`");
        }

        DB::statement(<<<'SQL'
            ALTER TABLE auth.audit_log
              ADD CONSTRAINT chk_audit_log_action
              CHECK (action IN ('viewed','acted_as','approved','rejected','refunded','suspended','acted','closed','reported'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE auth.audit_log DROP CONSTRAINT chk_audit_log_action');

        DB::statement(<<<'SQL'
            ALTER TABLE auth.audit_log
              ADD CONSTRAINT chk_audit_log_action_restored
              CHECK (action IN ('viewed','acted_as','approved','rejected','refunded','suspended','acted','closed'))
        SQL);
    }

    private function findActionCheckConstraint(): ?string
    {
        $row = DB::selectOne('SHOW CREATE TABLE auth.audit_log');
        $ddl = $row?->{'Create Table'};

        if ($ddl === null) {
            return null;
        }

        foreach ($this->extractCheckConstraints($ddl) as $name => $clause) {
            if ($name !== 'chk_audit_log_action' && str_contains($clause, 'action')) {
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
