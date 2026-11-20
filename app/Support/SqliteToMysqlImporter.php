<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;
use Throwable;

/**
 * One-shot copy of the SQLite library (the local dev harness that holds the real
 * data) into a freshly migrated MySQL database.
 *
 * Guarantees:
 *  - The SQLite file is opened READ-ONLY (URI mode=ro); it is never written.
 *  - The target must be fully migrated, have the same tables and columns as the
 *    source, and be empty — except `settings`, which may only hold the defaults
 *    seeded by the migrations (they are replaced by the source values).
 *  - Protected databases (entreprise, system schemas, the test database) are refused.
 *  - Everything runs in ONE InnoDB transaction with foreign keys ON, in dependency
 *    order: either every row is copied, or nothing is.
 *  - Ids are copied verbatim, so every relation is preserved.
 *  - After the copy, every row of every table is compared value by value with the
 *    source (inside the transaction). Any difference rolls everything back.
 *  - dryRun: performs the whole copy and comparison, then rolls back.
 */
final class SqliteToMysqlImporter
{
    public const PROTECTED_TARGETS = ['entreprise', 'mysql', 'sys', 'information_schema', 'performance_schema', 'mauro_library_test'];

    /** @var callable(string):void */
    private $log;

    /**
     * @param list<string> $protectedTargets databases that can never receive an import
     *        (only the integration test narrows it, to import into mauro_library_test)
     */
    public function __construct(private PDO $source, private PDO $target, ?callable $log = null, private array $protectedTargets = self::PROTECTED_TARGETS)
    {
        $this->log = $log ?? static function (string $m): void {
        };
        $this->source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->target->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function openSourceReadOnly(string $path): PDO
    {
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new RuntimeException("SQLite file not found: $path");
        }
        $pdo = new PDO('sqlite:file:' . str_replace('\\', '/', $real) . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ((string) $pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('SQLite integrity_check failed; refusing to import');
        }
        return $pdo;
    }

    /**
     * @param list<string> $expectedMigrations migration names that must all be applied on the target
     * @param array<int, array{0:string, 1:string}> $seededSettings [key, value] pairs the migrations insert
     * @return array<string, int> rows copied per table
     */
    public function run(array $expectedMigrations, array $seededSettings, bool $dryRun): array
    {
        $db = (string) $this->target->query('SELECT DATABASE()')->fetchColumn();
        if ($db === '' || in_array(strtolower($db), $this->protectedTargets, true) || in_array(strtolower($db), ['entreprise', 'mysql', 'sys', 'information_schema', 'performance_schema'], true)) {
            throw new RuntimeException("refusing to import into protected or unnamed database '$db'");
        }
        ($this->log)("target database: $db");

        $tables = $this->checkPreconditions($expectedMigrations, $seededSettings);
        $order = $this->dependencyOrder($tables);
        ($this->log)('insert order: ' . implode(' > ', $order));

        $copied = [];
        $this->target->beginTransaction();
        try {
            // Seeded defaults are replaced by the real values from the source.
            $this->target->exec('DELETE FROM `settings`');
            foreach ($order as $table) {
                $copied[$table] = $this->copyTable($table);
                ($this->log)(sprintf('copied %-22s %d row(s)', $table, $copied[$table]));
            }
            $this->applyDeferred();
            foreach ($order as $table) {
                $this->compareTable($table);
            }
            ($this->log)('row-by-row comparison: identical for every table');
            if ($dryRun) {
                $this->target->rollBack();
                ($this->log)('DRY RUN: rolled back, target unchanged');
            } else {
                $this->target->commit();
                ($this->log)('committed');
            }
        } catch (Throwable $e) {
            if ($this->target->inTransaction()) {
                $this->target->rollBack();
            }
            throw new RuntimeException('import rolled back: ' . $e->getMessage(), 0, $e);
        }
        return $copied;
    }

    /** @return list<string> source tables, validated against the target */
    private function checkPreconditions(array $expectedMigrations, array $seededSettings): array
    {
        $applied = $this->target->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff($expectedMigrations, $applied);
        if ($missing !== []) {
            throw new RuntimeException('target is not fully migrated, missing: ' . implode(', ', $missing));
        }

        $src = $this->source->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $dst = array_diff($this->target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), ['migrations']);
        if ($diff = array_merge(array_diff($src, $dst), array_diff($dst, $src))) {
            throw new RuntimeException('table sets differ: ' . implode(', ', $diff));
        }

        foreach ($src as $t) {
            $sc = array_column($this->source->query('PRAGMA table_info("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
            $dc = array_column($this->target->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            sort($sc);
            sort($dc);
            if ($sc !== $dc) {
                throw new RuntimeException("columns differ for table $t");
            }
            $count = (int) $this->target->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            if ($t === 'settings') {
                $rows = $this->target->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_NUM);
                $seeded = array_map(static fn (array $r) => json_encode([(string) $r[0], $r[1] === null ? null : (string) $r[1]]), $seededSettings);
                foreach ($rows as $r) {
                    if (!in_array(json_encode([(string) $r[0], $r[1] === null ? null : (string) $r[1]]), $seeded, true)) {
                        throw new RuntimeException("settings holds a non-default value for '{$r[0]}'; target is not fresh");
                    }
                }
            } elseif ($count !== 0) {
                throw new RuntimeException("target table $t is not empty ($count rows); refusing to merge");
            }
        }
        return $src;
    }

    /**
     * Columns inserted as NULL first and set afterwards, to break a foreign-key
     * cycle (e.g. books.current_file_id <-> book_files.book_id).
     *
     * @var array<string, list<string>>
     */
    private array $deferred = [];

    /**
     * Parents before children, from the target's declared foreign keys. A cycle is
     * broken only through NULLABLE foreign-key columns, which are deferred.
     */
    private function dependencyOrder(array $tables): array
    {
        $deps = array_fill_keys($tables, []);
        $fk = $this->target->query(
            'SELECT k.TABLE_NAME, k.REFERENCED_TABLE_NAME, k.COLUMN_NAME, c.IS_NULLABLE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.COLUMNS c
               ON c.TABLE_SCHEMA = k.TABLE_SCHEMA AND c.TABLE_NAME = k.TABLE_NAME AND c.COLUMN_NAME = k.COLUMN_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL'
        )->fetchAll(PDO::FETCH_NUM);
        foreach ($fk as [$child, $parent, $column, $nullable]) {
            if ($child !== $parent && isset($deps[$child])) {
                $deps[$child][$parent][] = ['column' => $column, 'nullable' => $nullable === 'YES'];
            }
        }
        $order = [];
        while ($deps !== []) {
            $ready = array_keys(array_filter($deps, static fn (array $d) => array_diff_key($d, array_flip($order)) === []));
            if ($ready === []) {
                // Break the cycle at a table whose pending dependencies are all nullable columns.
                foreach ($deps as $t => $d) {
                    $pending = array_diff_key($d, array_flip($order));
                    $cols = array_merge(...array_values($pending));
                    if (array_filter($cols, static fn (array $c) => !$c['nullable']) === []) {
                        $this->deferred[$t] = array_values(array_unique(array_merge($this->deferred[$t] ?? [], array_column($cols, 'column'))));
                        ($this->log)("cycle broken at $t: " . implode(', ', $this->deferred[$t]) . ' set after insert');
                        $deps[$t] = array_intersect_key($d, array_flip($order));
                        continue 2;
                    }
                }
                throw new RuntimeException('circular foreign keys without a nullable column between: ' . implode(', ', array_keys($deps)));
            }
            sort($ready);
            foreach ($ready as $t) {
                $order[] = $t;
                unset($deps[$t]);
            }
        }
        return $order;
    }

    /** @return list<string> primary-key columns (fallback: all columns) */
    private function orderColumns(string $table): array
    {
        $pk = [];
        foreach ($this->target->query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC) as $k) {
            $pk[(int) $k['Seq_in_index']] = $k['Column_name'];
        }
        ksort($pk);
        return $pk !== [] ? array_values($pk) : array_column($this->target->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    }

    private function copyTable(string $table): int
    {
        $cols = array_column($this->target->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $orderBy = implode(', ', array_map(static fn ($c) => '"' . $c . '"', $this->orderColumns($table)));
        $select = $this->source->query('SELECT ' . implode(', ', array_map(static fn ($c) => '"' . $c . '"', $cols)) . " FROM \"$table\" ORDER BY $orderBy");
        $insert = $this->target->prepare(
            "INSERT INTO `$table` (" . implode(', ', array_map(static fn ($c) => "`$c`", $cols)) . ') VALUES ('
            . implode(', ', array_map(static fn ($i) => ":p$i", array_keys($cols))) . ')'
        );
        $deferred = $this->deferred[$table] ?? [];
        $n = 0;
        while (($row = $select->fetch(PDO::FETCH_NUM)) !== false) {
            foreach ($row as $i => $v) {
                if (in_array($cols[$i], $deferred, true)) {
                    $v = null;
                }
                $insert->bindValue(":p$i", $v, $v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR));
            }
            $insert->execute();
            $n++;
        }
        return $n;
    }

    /** Second pass: set the deferred foreign-key columns now that their parents exist. */
    private function applyDeferred(): void
    {
        foreach ($this->deferred as $table => $columns) {
            $pk = $this->orderColumns($table);
            // Columns with ON UPDATE CURRENT_TIMESTAMP would be bumped by this UPDATE:
            // assign their source value explicitly so the original timestamp is kept.
            foreach ($this->target->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (stripos((string) $c['Extra'], 'on update') !== false && !in_array($c['Field'], $columns, true)) {
                    $columns[] = $c['Field'];
                }
            }
            $select = $this->source->query('SELECT ' . implode(', ', array_map(static fn ($c) => '"' . $c . '"', array_merge($pk, $columns))) . " FROM \"$table\"");
            $update = $this->target->prepare(
                "UPDATE `$table` SET " . implode(', ', array_map(static fn ($i, $c) => "`$c` = :d$i", array_keys($columns), $columns))
                . ' WHERE ' . implode(' AND ', array_map(static fn ($i, $c) => "`$c` = :k$i", array_keys($pk), $pk))
            );
            $n = 0;
            while (($row = $select->fetch(PDO::FETCH_NUM)) !== false) {
                foreach ($pk as $i => $c) {
                    $update->bindValue(":k$i", $row[$i]);
                }
                foreach ($columns as $i => $c) {
                    $v = $row[count($pk) + $i];
                    $update->bindValue(":d$i", $v, $v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR));
                }
                $update->execute();
                $n++;
            }
            ($this->log)("deferred $table." . implode(', ', $columns) . " set on $n row(s)");
        }
    }

    private function compareTable(string $table): void
    {
        $cols = array_column($this->target->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $order = $this->orderColumns($table);
        $src = $this->source->query('SELECT ' . implode(', ', array_map(static fn ($c) => '"' . $c . '"', $cols)) . " FROM \"$table\" ORDER BY " . implode(', ', array_map(static fn ($c) => '"' . $c . '"', $order)))->fetchAll(PDO::FETCH_NUM);
        $dst = $this->target->query('SELECT ' . implode(', ', array_map(static fn ($c) => "`$c`", $cols)) . " FROM `$table` ORDER BY " . implode(', ', array_map(static fn ($c) => "`$c`", $order)))->fetchAll(PDO::FETCH_NUM);
        if (count($src) !== count($dst)) {
            throw new RuntimeException("$table: row count differs (" . count($src) . ' vs ' . count($dst) . ')');
        }
        foreach ($src as $r => $row) {
            foreach ($row as $i => $v) {
                if (!self::same($v, $dst[$r][$i])) {
                    throw new RuntimeException("$table row #$r column {$cols[$i]}: value differs after copy");
                }
            }
        }
    }

    /** Strict equality, except numbers compare by value (SQLite 4.5 vs MySQL DECIMAL '4.50'). */
    public static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }
        if (is_numeric($a) && is_numeric($b) && (is_float($a) || is_float($b) || str_contains((string) $a, '.') || str_contains((string) $b, '.'))) {
            return abs((float) $a - (float) $b) < 1e-9;
        }
        return (string) $a === (string) $b;
    }
}
