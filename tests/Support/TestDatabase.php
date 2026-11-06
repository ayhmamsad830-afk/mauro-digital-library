<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use App\Core\Migrator;
use PDO;
use Throwable;

/**
 * Test databases.
 *
 *  sqlite() — in-memory SQLite with database/sqlite/schema.sql. Exercises the
 *             real repository/services SQL logic. NOT a substitute for MySQL.
 *  mysql()  — the real MySQL development TEST database, or null when it is
 *             unreachable (tests are then reported as SKIPPED, never passed).
 *             Destructive (drops all tables), so it only runs against a
 *             database whose name ends in "_test".
 */
final class TestDatabase
{
    public static function sqlite(): PDO
    {
        $pdo = Database::connectSqlite('');
        $pdo->exec((string) file_get_contents(BASE_PATH . '/database/sqlite/schema.sql'));
        Database::setConnection($pdo);
        return $pdo;
    }

    /** @return array{0: ?PDO, 1: string} [connection|null, reason] */
    public static function mysql(): array
    {
        $cfg = [
            'driver' => 'mysql',
            'host' => (string) (env('DB_TEST_HOST') ?: config('database.host')),
            'port' => (int) (env('DB_TEST_PORT') ?: config('database.port')),
            'database' => (string) (env('DB_TEST_DATABASE') ?: ''),
            'username' => (string) (env('DB_TEST_USERNAME') ?: config('database.username')),
            'password' => (string) (env('DB_TEST_PASSWORD') ?: config('database.password')),
            'socket' => (string) (env('DB_TEST_SOCKET') ?: config('database.socket')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'timeout' => 3,
        ];
        // Hard guards: destructive tests never touch production or the development database.
        if (config('app.env') === 'production') {
            return [null, 'BLOCKED: destructive MySQL tests are refused when APP_ENV=production'];
        }
        if ($cfg['database'] !== '' && $cfg['database'] === (string) config('database.database')
            && $cfg['host'] === (string) config('database.host') && $cfg['port'] === (int) config('database.port')) {
            return [null, 'BLOCKED: DB_TEST_DATABASE must differ from the development DB_DATABASE'];
        }
        if ($cfg['database'] === '') {
            return [null, 'BLOCKED: no MySQL test database configured (set DB_TEST_DATABASE=<name>_test and DB_TEST_* credentials)'];
        }
        if (!str_ends_with($cfg['database'], '_test')) {
            return [null, "BLOCKED: DB_TEST_DATABASE must end in _test (got {$cfg['database']}); tests drop all tables"];
        }
        if ($cfg['username'] === '') {
            return [null, 'BLOCKED: no MySQL username configured (DB_TEST_USERNAME)'];
        }
        try {
            $pdo = Database::connect($cfg);
        } catch (Throwable $e) {
            return [null, 'BLOCKED: MySQL unreachable — ' . strtok($e->getMessage(), "\n")];
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
