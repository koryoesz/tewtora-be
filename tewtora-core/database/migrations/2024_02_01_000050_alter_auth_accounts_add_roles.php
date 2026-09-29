<?php

use App\Shared\Support\CrossDatabaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §0 needs a `child | admin` account_type alongside
 * the original three. A child's own login is
 * auth.learner_profiles.linked_login_account_id -> an accounts row, which
 * had nowhere to record that under the old CHECK. `admin` covers internal
 * staff (verification review, safeguarding, support "act-as"). See
 * docs/api-gap-analysis.md §0 for why this reuses `accounts` rather than a
 * separate staff table.
 *
 * The original CHECK on account_type was unnamed, so MySQL auto-generated
 * its constraint name — looked up from information_schema rather than
 * guessed, since that name isn't guaranteed across MySQL versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards against a migrate replaying from scratch (migrations table
        // reset/recreated) while auth.accounts already has this constraint
        // AND the old inline one is actually gone — see CrossDatabaseSchema's
        // docblock, and hasStaleInlineAccountTypeCheck() below for why both
        // have to be checked, not just the first.
        if (CrossDatabaseSchema::constraintExists('auth', 'accounts', 'chk_accounts_account_type')
            && ! $this->hasStaleInlineAccountTypeCheck()) {
            return;
        }

        $constraint = $this->findAccountTypeCheckConstraint();

        if ($constraint !== null) {
            DB::statement("ALTER TABLE auth.accounts DROP CONSTRAINT `{$constraint}`");
        }

        // Some MariaDB versions/configs render the original CHECK with no
        // CONSTRAINT wrapper at all — just `account_type ... CHECK (...)`
        // directly on the column definition — which
        // findAccountTypeCheckConstraint() can't find or drop by name since
        // it has none (it only recognizes `CONSTRAINT \`name\` CHECK (`).
        // Redefining the column strips it regardless of naming style, and
        // is a harmless no-op when there's nothing to strip. This is what
        // silently let the old restrictive check survive every previous
        // run of this migration on that MariaDB.
        if ($this->hasStaleInlineAccountTypeCheck()) {
            DB::statement('ALTER TABLE auth.accounts MODIFY COLUMN account_type VARCHAR(20) NOT NULL');
        }

        if (! CrossDatabaseSchema::constraintExists('auth', 'accounts', 'chk_accounts_account_type')) {
            DB::statement(<<<'SQL'
                ALTER TABLE auth.accounts
                  ADD CONSTRAINT chk_accounts_account_type
                  CHECK (account_type IN ('parent','independent_student','child','teacher','admin'))
            SQL);
        }
    }

    /** Detects `` `account_type` ... CHECK (...) `` inline on the column definition itself, not as a separate named CONSTRAINT. */
    private function hasStaleInlineAccountTypeCheck(): bool
    {
        $row = DB::selectOne('SHOW CREATE TABLE auth.accounts');
        $ddl = $row?->{'Create Table'};

        return $ddl !== null && preg_match('/`account_type`[^,]*CHECK\s*\(/i', $ddl) === 1;
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE auth.accounts DROP CONSTRAINT chk_accounts_account_type');

        DB::statement(<<<'SQL'
            ALTER TABLE auth.accounts
              ADD CONSTRAINT chk_accounts_account_type
              CHECK (account_type IN ('parent','independent_student','teacher'))
        SQL);
    }

    /**
     * information_schema.CHECK_CONSTRAINTS reports a synthetic name for
     * this inline column-level CHECK (the column's own name, here
     * "account_type") on both engines — but MariaDB's ALTER TABLE ...
     * DROP CONSTRAINT rejects that synthetic name with error 1091 even
     * though information_schema lists it, while MySQL 8 accepts it. Read
     * the name straight out of SHOW CREATE TABLE instead: that's the exact
     * identifier either engine will actually let you drop by.
     *
     * SHOW CREATE TABLE wraps CHECK clauses in a doubled paren
     * (`CHECK ((\`account_type\` in (...)))`), so a fixed-depth regex isn't
     * enough — clauses are extracted with a paren-balancing scan instead.
     * Excludes chk_accounts_account_type by name so a retry after this
     * migration partially ran (constraint added, old one not yet dropped)
     * still finds the original rather than re-matching its own successor.
     */
    private function findAccountTypeCheckConstraint(): ?string
    {
        $row = DB::selectOne('SHOW CREATE TABLE auth.accounts');
        $ddl = $row?->{'Create Table'};

        if ($ddl === null) {
            return null;
        }

        foreach ($this->extractCheckConstraints($ddl) as $name => $clause) {
            if ($name !== 'chk_accounts_account_type' && str_contains($clause, 'account_type')) {
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
