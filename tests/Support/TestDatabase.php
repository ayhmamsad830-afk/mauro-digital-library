<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use App\Core\Migrator;
use App\Support\TestDatabaseConfig;
use PDO;
use Throwable;

/**
 * Test databases.
 *
 *  sqlite() — in-memory SQLite with database/sqlite/schema.sql. Exercises the
 *             real repository/services SQL logic. NOT a substitute for MySQL.
 *  mysql()  — the real MySQL TEST database, or null when it is unreachable or
 *             refused (tests are then reported as SKIPPED, never passed).
 *             Destructive (drops all tables), so it only runs against the
 *             dedicated local database `mauro_library_test`, with its own
 *             account, and every DB_TEST_* setting must be present.
 */
final class TestDatabase
{
    public static function sqlite(): PDO
    {
        // MAURO_HARNESS=mysql runs every harness-based test against a fresh, migrated
        // MySQL TEST database instead (same guards as mysql()); skipped if unavailable.
        if (getenv('MAURO_HARNESS') === 'mysql') {
            [$pdo, $reason] = self::mysql();
            if ($pdo === null) {
                throw new \Tests\SkippedTest($reason);
            }
            return $pdo;
        }
        $pdo = Database::connectSqlite('');
        $pdo->exec((string) file_get_contents(BASE_PATH . '/database/sqlite/schema.sql'));
        Database::setConnection($pdo);
        return $pdo;
    }

    /** @return array{0: ?PDO, 1: string} [connection|null, reason] */
    public static function mysql(): array
    {
        // Every DB_TEST_* value is mandatory and the development credentials are never
        // substituted; then hard guards (production, protected/development databases,
        // shared account, remote server) — see App\Support\TestDatabaseConfig.
        try {
            $cfg = TestDatabaseConfig::resolve();
            TestDatabaseConfig::assertSafeForDestructiveTests($cfg, (string) config('app.env'), (array) config('database', []));
        } catch (Throwable $e) {
            return [null, 'BLOCKED: ' . $e->getMessage()];
        }
        try {
            $pdo = Database::connect($cfg);
        } catch (Throwable $e) {
            return [null, 'BLOCKED: MySQL unreachable — ' . strtok($e->getMessage(), "\n")];
        }
        // Defence in depth: confirm the server really put us in the dedicated test schema
        // before a single table is dropped.
        $current = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($current !== TestDatabaseConfig::ALLOWED_DATABASE) {
            return [null, "BLOCKED: connected to '$current' instead of '" . TestDatabaseConfig::ALLOWED_DATABASE . "'; nothing was dropped"];
        }

        // Fresh schema from the real migrations.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', $t) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        (new Migrator($pdo, BASE_PATH . '/database/migrations'))->migrate();
        Database::setConnection($pdo);
        return [$pdo, 'ok'];
    }

    /** Restore the application's own (lazy) connection. */
    public static function release(): void
    {
        Database::setConnection(null);
        \App\Repositories\LibraryRepositoryFactory::set(null);
    }
}
