<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Registry;

use Illuminate\Support\Facades\Schema;

/**
 * Checks the registry against a schema and returns every violation as a
 * sentence that names the table or column and the file to edit. Empty means
 * the registry decides everything the schema contains.
 *
 * The five failure modes of plan §4.2:
 *   1. a table the registry does not name;
 *   2. a column, on a column-level table, the registry does not name;
 *   3. a registry entry for a table or column that no longer exists (stale);
 *   4. a foreign key to `users` / `creators` that the registry does not declare
 *      (or declares and the schema no longer has), on ANY table;
 *   5. a PII-shaped column on a table-level (N/A / EXPIRE) table that is not
 *      explicitly classified.
 * Plus malformed entries (ANON without a value, NULL on a NOT NULL column, a
 * rule with no reason), because an ANON rule that cannot execute is a hole.
 *
 * The check takes a plain array so a spec can feed it a mutated schema; the
 * migrated database is read by {@see self::snapshot()}.
 */
final class RegistryVerifier
{
    /**
     * Name segments (split on `_`) that mark a column as PII-shaped. A segment
     * match, not a substring, so `expiration` does not match `ip`.
     */
    public const PII_SEGMENTS = [
        'email', 'name', 'phone', 'whatsapp', 'address', 'ip', 'token', 'dob', 'birth',
        'handle', 'agent', 'bio', 'avatar', 'tax', 'street', 'postal', 'secret',
    ];

    private const FILE = 'app/Modules/Erasure/Registry/ErasureRegistry.php';

    /** The tables whose foreign keys the registry must declare. */
    private const REFERENCED = ['users', 'creators'];

    /**
     * @return array<string, array{columns: array<string, bool>, refs: list<string>}> table => column => nullable, and the FK columns pointing at users/creators.
     */
    public static function snapshot(): array
    {
        $out = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if ($name === 'migrations') {
                continue;
            }
            $columns = [];
            foreach (Schema::getColumns($name) as $column) {
                $columns[$column['name']] = $column['nullable'];
            }
            $refs = [];
            foreach (Schema::getForeignKeys($name) as $fk) {
                if (in_array($fk['foreign_table'], self::REFERENCED, true)) {
                    foreach ($fk['columns'] as $col) {
                        $refs[] = $col;
                    }
                }
            }
            sort($refs);
            $out[$name] = ['columns' => $columns, 'refs' => $refs];
        }

        return $out;
    }

    /**
     * @param  array<string, array{columns: array<string, bool>, refs: list<string>}>  $schema
     * @param  array<string, TableRule>  $registry
     * @return list<string>
     */
    public static function violations(array $schema, array $registry): array
    {
        $v = [];

        foreach ($schema as $table => $actual) {
            $rule = $registry[$table] ?? null;
            if ($rule === null) {
                $v[] = "Unclassified table `{$table}`: add it to ".self::FILE.' (column level if it holds creator data or points at users/creators, otherwise N/A with a reason).';

                continue;
            }

            // 4. foreign keys to users / creators.
            $declared = $rule->refs;
            sort($declared);
            foreach (array_diff($actual['refs'], $declared) as $col) {
                $v[] = "New foreign key `{$table}.{$col}` to users/creators is not declared: add it to `refs` for `{$table}` in ".self::FILE.' and decide what erasure does with it.';
            }
            foreach (array_diff($declared, $actual['refs']) as $col) {
                $v[] = "Stale ref: the registry declares `{$table}.{$col}` as a foreign key to users/creators but the schema has none; fix `refs` in ".self::FILE.'.';
            }

            if ($rule->isColumnLevel()) {
                // 2. a column the registry does not name.
                foreach (array_diff_key($actual['columns'], $rule->columns) as $col => $nullable) {
                    $v[] = "Unclassified column `{$table}.{$col}`: decide it (ANON / ERASE-ROW / KEEP / SCRUB-JSON / DEL-MATCH) in ".self::FILE.'.';
                }
                // 3. stale column.
                foreach (array_diff_key($rule->columns, $actual['columns']) as $col => $_) {
                    $v[] = "Stale entry `{$table}.{$col}`: the column no longer exists; remove or rename it in ".self::FILE.'.';
                }
                // malformed column rules.
                foreach ($rule->columns as $col => $cr) {
                    if (! array_key_exists($col, $actual['columns'])) {
                        continue;
                    }
                    array_push($v, ...self::columnProblems($table, $col, $cr, $actual['columns'][$col]));
                }
                foreach ($rule->classified as $col => $_) {
                    $v[] = "Table `{$table}` is column-level, so `classified` is meaningless for `{$col}`; remove it in ".self::FILE.'.';
                }
            } else {
                // 5. PII-shaped column on a table-level table that is not classified.
                foreach (array_keys($actual['columns']) as $col) {
                    if (self::looksPersonal($col) && ! array_key_exists($col, $rule->classified)) {
                        $v[] = "PII-shaped column `{$table}.{$col}` on a table-level table: classify it under `classified` for `{$table}` (with the reason it is not creator data), or make the table column-level, in ".self::FILE.'.';
                    }
                }
                foreach ($rule->classified as $col => $_) {
                    if (! array_key_exists($col, $actual['columns'])) {
                        $v[] = "Stale classification `{$table}.{$col}`: the column no longer exists; remove it in ".self::FILE.'.';
                    }
                }
            }

            if (trim($rule->reason) === '') {
                $v[] = "Table `{$table}` has no reason in ".self::FILE.'.';
            }
            if ($rule->disposition === Disposition::Anon || $rule->disposition === Disposition::Keep || $rule->disposition === Disposition::ScrubJson) {
                $v[] = "Table `{$table}` has table-level disposition {$rule->disposition->value}, which only makes sense per column; use null (column-wise) in ".self::FILE.'.';
            }
        }

        // 3. stale table.
        foreach (array_diff_key($registry, $schema) as $table => $_) {
            $v[] = "Stale entry for table `{$table}`: it no longer exists; remove it from ".self::FILE.'.';
        }

        return $v;
    }

    public static function looksPersonal(string $column): bool
    {
        return array_intersect(explode('_', $column), self::PII_SEGMENTS) !== [];
    }

    /** @return list<string> */
    private static function columnProblems(string $table, string $col, ColumnRule $rule, bool $nullable): array
    {
        $p = [];
        if (trim($rule->reason) === '') {
            $p[] = "Column `{$table}.{$col}` has no reason in ".self::FILE.'.';
        }
        if ($rule->disposition === Disposition::Anon && $rule->anon === null) {
            $p[] = "ANON column `{$table}.{$col}` has no AnonValue: an ANON rule that cannot execute is a hole.";
        }
        if ($rule->disposition !== Disposition::Anon && $rule->anon !== null) {
            $p[] = "Column `{$table}.{$col}` carries an AnonValue but is {$rule->disposition->value}, not ANON.";
        }
        if ($rule->anon === AnonValue::Null && ! $nullable) {
            $p[] = "ANON column `{$table}.{$col}` is NULL on a NOT NULL column: use a sentinel AnonValue (never a migration).";
        }
        if ($rule->disposition === Disposition::ScrubJson && ($rule->detail === null || trim($rule->detail) === '')) {
            $p[] = "SCRUB-JSON column `{$table}.{$col}` has no selection detail (which keys, on which rows).";
        }

        return $p;
    }
}
