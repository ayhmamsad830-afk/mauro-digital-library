<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Test servers must never reach the developer's real database (the library in
 * storage/mauro.sqlite, or mauro_library_dev once DB_DRIVER=mysql), whatever .env says.
 */
final class HttpServerIsolationTest extends TestCase
{
    public function testWithoutADriverOverrideTheServerGetsAFreshScratchSqlite(): void
    {
        $env = HttpServer::isolate(['APP_DEMO_UI' => 'true'], 'isolation-test-a');
        $this->assertSame('sqlite', $env['DB_DRIVER']);
        $path = $env['DB_SQLITE_PATH'];
        $this->assertTrue(str_starts_with($path, sys_get_temp_dir()), 'scratch database lives in the temp directory');
        $this->assertTrue(is_file($path), 'scratch database created');
        $real = (string) env('DB_SQLITE_PATH', '');
        if ($real !== '') {
            $this->assertFalse(realpath($path) === realpath($real), 'never the .env SQLite file');
        }
        $pdo = new \PDO('sqlite:' . $path);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn(), 'empty, current schema');
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $pdo = null;
        @unlink($path);
    }

    public function testMysqlWithoutAnExplicitUserMeansNoDatabase(): void
    {
        $env = HttpServer::isolate(['DB_DRIVER' => 'mysql'], 'isolation-test-b');
        $this->assertSame('', $env['DB_USERNAME'], 'the .env credentials (mauro_library_dev) are never inherited');
    }

    public function testExplicitTestDatabasesAreRespected(): void
    {
        $env = HttpServer::isolate(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => '/tmp/e2e.sqlite'], 'isolation-test-c');
        $this->assertSame('/tmp/e2e.sqlite', $env['DB_SQLITE_PATH']);
        $env = HttpServer::isolate(['DB_DRIVER' => 'mysql', 'DB_USERNAME' => ''], 'isolation-test-d');
        $this->assertSame('', $env['DB_USERNAME']);
    }
}
