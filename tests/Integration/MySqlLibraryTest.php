<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\LibraryFixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Authoritative integration run against the real MySQL TEST database: applies
 * the real migrations to a fresh *_test schema, loads fixtures, then runs the
 * same repository + admin contracts. When MySQL is unreachable every test is
 * reported as SKIPPED (BLOCKED) — never as passed.
 */
final class MySqlLibraryTest extends TestCase
{
    use LibraryRepositoryContract;
    use AdminServicesContract;

    public function setUp(): void
    {
        [$pdo, $reason] = TestDatabase::mysql();
        if ($pdo === null) {
            $this->markSkipped($reason);
        }
        $this->ids = LibraryFixtures::load();
    }

    public function tearDown(): void
    {
        TestDatabase::release();
    }

    public function testServerIsInnoDbUtf8mb4WithAllMigrations(): void
    {
        $this->assertSame(11, (int) \App\Core\Database::scalar('SELECT COUNT(*) FROM migrations'));
        $engine = \App\Core\Database::scalar("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'books'");
        $this->assertSame('InnoDB', $engine);
        $collation = \App\Core\Database::scalar("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'books'");
        $this->assertSame('utf8mb4_unicode_ci', $collation);
    }
}
