<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProcessRunner;
use App\Support\TestDatabaseConfig;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * DEV/TEST separation (audit finding I4): the test database settings never fall
 * back to the development credentials, and the destructive suite only ever runs
 * against the dedicated local `mauro_library_test` database.
 */
final class TestDatabaseConfigTest extends TestCase
{
    private const VALID = [
        'DB_TEST_HOST' => '127.0.0.1',
        'DB_TEST_PORT' => '3306',
        'DB_TEST_DATABASE' => 'mauro_library_test',
        'DB_TEST_USERNAME' => 'mauro_test',
        'DB_TEST_PASSWORD' => 'test-secret',
    ];

    private const DEV = ['database' => 'mauro_library_dev', 'username' => 'mauro_dev', 'password' => 'dev-secret', 'host' => '127.0.0.1'];

    public function testCompleteSettingsResolveToTheTestAccountOnly(): void
    {
        $c = TestDatabaseConfig::resolve(self::VALID);
        $this->assertSame('mysql', $c['driver']);
        $this->assertSame('127.0.0.1', $c['host']);
        $this->assertSame(3306, $c['port']);
        $this->assertSame('mauro_library_test', $c['database']);
        $this->assertSame('mauro_test', $c['username']);
        $this->assertSame('test-secret', $c['password']);
        $this->assertSame('', $c['socket']);
    }

    public function testEveryRequiredSettingIsMandatoryAndNeverFallsBackToDevelopment(): void
    {
        foreach (TestDatabaseConfig::REQUIRED as $key) {
            foreach (['missing' => null, 'empty' => '', 'blank' => '   '] as $case => $value) {
                $env = self::VALID;
                if ($value === null) {
                    unset($env[$key]);
                } else {
                    $env[$key] = $value;
                }
                $this->assertThrows(InvalidArgumentException::class, static fn () => TestDatabaseConfig::resolve($env),
                    function (\Throwable $e) use ($key, $case): void {
                        $this->assertContains($key, $e->getMessage(), "$key ($case) must be named");
                        $this->assertContains('never used as a fallback', $e->getMessage());
                        // The error must not leak any credential.
                        $this->assertNotContains('test-secret', $e->getMessage());
                    });
            }
        }
    }

    public function testAllMissingSettingsAreReportedTogether(): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => TestDatabaseConfig::resolve([]),
            function (\Throwable $e): void {
                foreach (TestDatabaseConfig::REQUIRED as $key) {
                    $this->assertContains($key, $e->getMessage());
                }
            });
    }

    public function testPortMustBeANumber(): void
    {
        foreach (['abc', '0', '70000', '33o6'] as $port) {
            $this->assertThrows(InvalidArgumentException::class, static fn () => TestDatabaseConfig::resolve(['DB_TEST_PORT' => $port] + self::VALID));
        }
    }

    public function testDestructiveGuardAcceptsTheDedicatedLocalTestDatabase(): void
    {
        TestDatabaseConfig::assertSafeForDestructiveTests(TestDatabaseConfig::resolve(self::VALID), 'local', self::DEV);
        TestDatabaseConfig::assertSafeForDestructiveTests(TestDatabaseConfig::resolve(['DB_TEST_HOST' => 'localhost'] + self::VALID), 'testing', self::DEV);
        $this->assertTrue(true);
    }

    public function testDestructiveGuardRefusals(): void
    {
        $cases = [
            'production' => [self::VALID, 'production', self::DEV, 'APP_ENV=production'],
            'development db' => [['DB_TEST_DATABASE' => 'mauro_library_dev'] + self::VALID, 'local', self::DEV, 'protected database'],
            'personal db entreprise' => [['DB_TEST_DATABASE' => 'entreprise'] + self::VALID, 'local', self::DEV, 'protected database'],
            'system db' => [['DB_TEST_DATABASE' => 'mysql'] + self::VALID, 'local', self::DEV, 'protected database'],
            'other _test db' => [['DB_TEST_DATABASE' => 'shop_test'] + self::VALID, 'local', self::DEV, "only run against 'mauro_library_test'"],
            'case-folded protected' => [['DB_TEST_DATABASE' => 'Entreprise'] + self::VALID, 'local', self::DEV, 'protected database'],
            'same as dev db' => [self::VALID, 'local', ['database' => 'mauro_library_test'] + self::DEV, 'must differ from the development DB_DATABASE'],
            'shared account' => [['DB_TEST_USERNAME' => 'mauro_dev'] + self::VALID, 'local', self::DEV, 'DB_TEST_USERNAME must differ'],
            'remote server' => [['DB_TEST_HOST' => 'db.example.com'] + self::VALID, 'local', self::DEV, 'local server'],
        ];
        foreach ($cases as $name => [$env, $appEnv, $dev, $expected]) {
            $this->assertThrows(RuntimeException::class,
                static fn () => TestDatabaseConfig::assertSafeForDestructiveTests(TestDatabaseConfig::resolve($env), $appEnv, $dev),
                fn (\Throwable $e) => $this->assertContains($expected, $e->getMessage(), $name));
        }
    }

    /**
     * End to end: `bin/console db:check --test` with an empty DB_TEST_USERNAME must
     * stop with a non-zero exit code, name the setting, and never even attempt a
     * connection with the development account. The Env sentinel "(empty)" is used
     * because Windows drops genuinely empty environment variables from child processes.
     */
    public function testDbCheckTestRefusesInsteadOfFallingBackToDevelopment(): void
    {
        if (!ProcessRunner::available()) {
            $this->markSkipped('proc_open unavailable');
        }
        foreach (['DB_TEST_USERNAME', 'DB_TEST_PASSWORD', 'DB_TEST_DATABASE'] as $key) {
            $res = $this->console(['db:check', '--test'], [$key => '(empty)']);
            $out = $res['stdout'] . $res['stderr'];
            $this->assertSame(2, $res['exit'], "$key empty → exit code 2. Output: $out");
            $this->assertContains($key, $out);
            $this->assertNotContains('Connecting to', $out, "$key empty must not trigger any connection attempt");
        }
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    private function console(array $args, array $env): array
    {
        $cmd = array_merge([PHP_BINARY, BASE_PATH . '/bin/console'], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, array_merge(getenv(), $env), ['bypass_shell' => true]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
