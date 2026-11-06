<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Lazy PDO wrapper. The connection is opened on first use so that pages which
 * do not need the database (static pages, error pages) still render when the
 * database is unavailable.
 *
 * All queries MUST use bound parameters. Never interpolate user input into SQL.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect(Config::get('database', []));
        }
        return self::$pdo;
    }

    /** @param array<string, mixed> $c */
    public static function connect(array $c): PDO
    {
        if (($c['driver'] ?? 'mysql') === 'sqlite') {
            return self::connectSqlite((string) ($c['sqlite_path'] ?? ''));
        }
        $dsn = !empty($c['socket'])
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $c['socket'], $c['database'], $c['charset'])
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int) $c['port'], $c['database'], $c['charset']);

        $pdo = new PDO($dsn, (string) $c['username'], (string) $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => (int) ($c['timeout'] ?? 5),
        ]);
        $pdo->exec("SET NAMES {$c['charset']} COLLATE {$c['collation']}");
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    /**
     * Local development / test harness only (config forces 'mysql' in production).
     * Uses the portable schema in tests/fixtures/sqlite_schema.sql.
     */
    public static function connectSqlite(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . ($path === '' ? ':memory:' : $path), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    public static function driver(): string
    {
        return (string) self::connection()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** For tests: inject a connection (e.g. a mock or a test database). */
    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /** @param array<int|string, mixed> $params */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return array<string, mixed>|null */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function lastInsertId(): int
    {
        return (int) self::connection()->lastInsertId();
    }

    /**
     * Run a callback inside a transaction; rolls back on any exception.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Connectivity probe used by `bin/console db:check`.
     *
     * @return array{ok: bool, message: string, server_version?: string}
     */
    public static function check(): array
    {
        try {
            $pdo = self::connection();
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $engine = $pdo->query("SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'")->fetchColumn();
            return [
                'ok' => in_array($engine, ['DEFAULT', 'YES'], true),
                'message' => $engine ? "InnoDB: $engine" : 'InnoDB not available',
                'server_version' => $version,
            ];
        } catch (Throwable $e) {
            // Never leak credentials: PDO messages do not contain the password,
            // but we still keep only the first line.
            return ['ok' => false, 'message' => strtok($e->getMessage(), "\n") ?: 'Connection failed'];
        }
    }
}
