<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Single source of truth for the MySQL TEST database settings (DB_TEST_*).
 *
 * Used by `bin/console db:check --test` and by the destructive integration
 * test harness (tests/Support/TestDatabase.php). Two rules:
 *
 *  1. resolve(): every DB_TEST_* setting is mandatory. A missing or empty value
 *     is an error — the development credentials (DB_*) are NEVER used as a
 *     fallback, so a check can never report "OK" while silently testing the
 *     development account.
 *  2. assertSafeForDestructiveTests(): the integration suite drops every table
 *     of the test database, so it only runs against the dedicated local
 *     database `mauro_library_test`, with an account distinct from development.
 */
final class TestDatabaseConfig
{
    /** Settings that must all be present and non-empty. DB_TEST_SOCKET stays optional. */
    public const REQUIRED = ['DB_TEST_HOST', 'DB_TEST_PORT', 'DB_TEST_DATABASE', 'DB_TEST_USERNAME', 'DB_TEST_PASSWORD'];

    /** The only database the destructive suite may touch. */
    public const ALLOWED_DATABASE = 'mauro_library_test';

    /** Never acceptable as a destructive target, whatever the configuration says. */
    public const PROTECTED_DATABASES = ['mauro_library_dev', 'mauro_library', 'entreprise', 'mysql', 'sys', 'information_schema', 'performance_schema'];

    private const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    /**
     * @param array<string, mixed>|null $env values to use instead of the real environment (tests)
     * @return array{driver:string, host:string, port:int, database:string, username:string, password:string, socket:string, charset:string, collation:string, timeout:int}
     * @throws InvalidArgumentException when a required setting is missing, empty or malformed
     */
    public static function resolve(?array $env = null): array
    {
        $read = static function (string $key) use ($env): string {
            $value = $env !== null ? ($env[$key] ?? null) : env($key);
            return is_string($value) || is_int($value) ? trim((string) $value) : '';
        };

        $values = [];
        $missing = [];
        foreach (self::REQUIRED as $key) {
            $values[$key] = $read($key);
            if ($values[$key] === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Missing or empty test database setting(s): ' . implode(', ', $missing)
                . '. Set every DB_TEST_* value in .env; the development credentials are never used as a fallback.'
            );
        }
        if (!ctype_digit($values['DB_TEST_PORT']) || (int) $values['DB_TEST_PORT'] < 1 || (int) $values['DB_TEST_PORT'] > 65535) {
            throw new InvalidArgumentException('DB_TEST_PORT must be a TCP port number (1-65535).');
        }

        return [
            'driver' => 'mysql',
            'host' => $values['DB_TEST_HOST'],
            'port' => (int) $values['DB_TEST_PORT'],
            'database' => $values['DB_TEST_DATABASE'],
            'username' => $values['DB_TEST_USERNAME'],
            'password' => $values['DB_TEST_PASSWORD'],
            'socket' => $read('DB_TEST_SOCKET'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'timeout' => 3,
        ];
    }

    /**
     * Refuses any configuration that is not the dedicated, local test database.
     *
     * @param array{database:string, username:string, host:string, socket?:string} $test
     * @param array<string, mixed> $development the application (DB_*) connection settings
     * @throws RuntimeException with the reason, when destructive tests must not run
     */
    public static function assertSafeForDestructiveTests(array $test, string $appEnv, array $development): void
    {
        if ($appEnv === 'production') {
            throw new RuntimeException('destructive MySQL tests are refused when APP_ENV=production');
        }
        $database = strtolower(trim($test['database']));
        if ($database === '') {
            throw new RuntimeException('the test database name is empty');
        }
        if (in_array($database, self::PROTECTED_DATABASES, true)) {
            throw new RuntimeException("'$database' is a protected database and can never be used for destructive tests");
        }
        if ($database !== self::ALLOWED_DATABASE) {
            throw new RuntimeException("destructive tests only run against '" . self::ALLOWED_DATABASE . "' (got '$database'); every table in it is dropped");
        }
        if ($database === strtolower(trim((string) ($development['database'] ?? '')))) {
            throw new RuntimeException('DB_TEST_DATABASE must differ from the development DB_DATABASE');
        }
        if (strtolower($test['username']) === strtolower(trim((string) ($development['username'] ?? '')))) {
            throw new RuntimeException('DB_TEST_USERNAME must differ from the development DB_USERNAME (dedicated test account required)');
        }
        if (($test['socket'] ?? '') === '' && !in_array(strtolower($test['host']), self::LOOPBACK_HOSTS, true)) {
            throw new RuntimeException("destructive tests only run against a local server (127.0.0.1, localhost or ::1), not '{$test['host']}'");
        }
    }
}
