<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\LibraryFixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Runs the repository + admin contracts on in-memory SQLite (database/sqlite/schema.sql).
 * This validates query LOGIC with the real classes; it is NOT a MySQL test —
 * see MySqlLibraryTest for the authoritative run against MySQL.
 */
final class SqliteLibraryTest extends TestCase
{
    use LibraryRepositoryContract;
    use AdminServicesContract;

    public function setUp(): void
    {
        TestDatabase::sqlite();
        $this->ids = LibraryFixtures::load();
    }

    public function tearDown(): void
    {
        TestDatabase::release();
    }
}
