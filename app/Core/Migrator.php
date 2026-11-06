<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Applies ordered plain-SQL migrations from database/migrations.
 *
 * MySQL DDL statements commit implicitly, so a file cannot be rolled back as
 * a unit. Each file is therefore kept small and focused, and is only recorded
 * in `migrations` after all of its statements succeed. A failed file must be
 * fixed and the partially created objects dropped manually before re-running
 * (the error output names the failing statement).
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration VARCHAR(191) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migrations_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> migration names (file basenames without .sql), sorted */
    public function files(): array
    {
        $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        $names = array_map(static fn (string $f): string => basename($f, '.sql'), $files);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return list<string> */
    public function applied(): array
    {
        $this->ensureTable();
        return $this->pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_values(array_diff($this->files(), $this->applied()));
    }

    /**
     * @param callable(string): void|null $output
     * @return list<string> applied migration names
     */
    public function migrate(?callable $output = null): array
    {
        $pending = $this->pending();
        if ($pending === []) {
            return [];
        }
        $batch = (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();
        $done = [];

        foreach ($pending as $name) {
            $sql = (string) file_get_contents($this->directory . '/' . $name . '.sql');
            foreach (self::splitStatements($sql) as $i => $statement) {
                try {
                    $this->pdo->exec($statement);
                } catch (Throwable $e) {
                    throw new RuntimeException(sprintf(
                        "Migration %s failed at statement #%d: %s\n---\n%s",
                        $name,
                        $i + 1,
                        $e->getMessage(),
                        mb_substr($statement, 0, 400)
                    ), 0, $e);
                }
            }
            $stmt = $this->pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (:m, :b)');
            $stmt->execute([':m' => $name, ':b' => $batch]);
            $done[] = $name;
            if ($output) {
                $output($name);
            }
        }
        return $done;
    }

    /**
     * Split a SQL file into statements on semicolons that end a line, after
     * removing full-line `--` comments. Sufficient for our DDL files, which
     * contain no stored procedures or semicolons inside string literals.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $clean = array_filter($lines, static fn (string $l): bool => !preg_match('/^\s*--/', $l));
        $parts = preg_split('/;\s*$/m', implode("\n", $clean)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
