<?php

namespace App\Shared\Support;

use Illuminate\Support\Facades\DB;

/**
 * `Schema::hasTable()` only checks the connection's own default database
 * (tewtora_core) — every domain table lives in a separate physical
 * database (auth/recommendation/core), so migrations that create them
 * with raw `DB::unprepared(CREATE TABLE ...)` need to check
 * information_schema directly instead. Exists specifically to guard
 * against a migrate replaying from the beginning (the migrations table
 * reset or recreated, e.g. by `migrate:fresh`, which can only ever drop
 * tewtora_core's own tables) while a domain database still has its
 * tables from before — hit for real more than once.
 */
class CrossDatabaseSchema
{
    public static function tableExists(string $schema, string $table): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$schema, $table],
        ) !== null;
    }

    public static function columnExists(string $schema, string $table, string $column): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$schema, $table, $column],
        ) !== null;
    }

    /** Covers CHECK, UNIQUE, FOREIGN KEY, etc. — any named table constraint. */
    public static function constraintExists(string $schema, string $table, string $constraint): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ?',
            [$schema, $table, $constraint],
        ) !== null;
    }

    public static function triggerExists(string $schema, string $trigger): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.triggers WHERE trigger_schema = ? AND trigger_name = ?',
            [$schema, $trigger],
        ) !== null;
    }
}
