<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Support\SqliteToMysqlImporter;
use RuntimeException;
use Tests\Support\LibraryFixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * SQLite -> MySQL import (bin/import-sqlite-to-mysql.php): the fixture catalogue
 * is built on the SQLite harness, then copied into a fresh MySQL TEST schema.
 * SKIPPED when the MySQL test database is unavailable.
 */
final class SqliteImportTest extends TestCase
{
    private const SEEDED = [['catalog.per_page', '24'], ['home.featured_book_id', null], ['site.contact_email', null]];

    public function tearDown(): void
    {
        TestDatabase::release();
    }

    /** @return array{0:\PDO, 1:\PDO, 2:list<string>} [source, target, migrations] */
    private function databases(): array
    {
        // Always a real SQLite source, even when MAURO_HARNESS=mysql redirects the harness.
        $source = Database::connectSqlite('');
        $source->exec((string) file_get_contents(BASE_PATH . '/database/sqlite/schema.sql'));
        Database::setConnection($source);
        $ids = LibraryFixtures::load();
        // Creates the books.current_file_id <-> book_files.book_id cycle.
        LibraryFixtures::attachReadyFile($ids['b1']);
        Database::query("UPDATE settings SET setting_value = '12' WHERE setting_key = 'catalog.per_page'");

        [$target, $reason] = TestDatabase::mysql();
        if ($target === null) {
            $this->markSkipped($reason);
        }
        $migrations = array_map(static fn ($f) => basename($f, '.sql'), glob(BASE_PATH . '/database/migrations/*.sql') ?: []);
        return [$source, $target, $migrations];
    }

    private function importer(\PDO $source, \PDO $target): SqliteToMysqlImporter
    {
        // Production refuses mauro_library_test; only this test narrows the list.
        return new SqliteToMysqlImporter($source, $target, null, ['entreprise', 'mauro_library_dev']);
    }

    public function testImportCopiesEveryRowAndBreaksTheFileCycle(): void
    {
        [$source, $target, $migrations] = $this->databases();
        $copied = $this->importer($source, $target)->run($migrations, self::SEEDED, false);

        foreach ($copied as $table => $n) {
            $this->assertSame((int) $source->query("SELECT COUNT(*) FROM \"$table\"")->fetchColumn(), (int) $target->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(), $table);
        }
        $this->assertTrue(array_sum($copied) > 20, 'fixtures were copied');
        $this->assertSame(
            (int) $source->query('SELECT current_file_id FROM books WHERE current_file_id IS NOT NULL')->fetchColumn(),
            (int) $target->query('SELECT current_file_id FROM books WHERE current_file_id IS NOT NULL')->fetchColumn(),
            'deferred foreign key restored'
        );
        $this->assertSame(
            $source->query('SELECT updated_at FROM books ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN),
            $target->query('SELECT updated_at FROM books ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN),
            'ON UPDATE CURRENT_TIMESTAMP did not bump updated_at'
        );
        $this->assertSame('12', (string) $target->query("SELECT setting_value FROM settings WHERE setting_key = 'catalog.per_page'")->fetchColumn(), 'source settings replace the seeded defaults');
        $this->assertSame('الأدب', (string) $target->query("SELECT name_ar FROM categories WHERE slug = 'literature'")->fetchColumn(), 'Unicode preserved');
    }

    public function testDryRunLeavesTheTargetEmpty(): void
    {
        [$source, $target, $migrations] = $this->databases();
        $this->importer($source, $target)->run($migrations, self::SEEDED, true);
        $this->assertSame(0, (int) $target->query('SELECT COUNT(*) FROM books')->fetchColumn());
        $this->assertSame(3, (int) $target->query('SELECT COUNT(*) FROM settings')->fetchColumn(), 'seeded defaults kept');
    }

    public function testASecondImportIsRefused(): void
    {
        [$source, $target, $migrations] = $this->databases();
        $this->importer($source, $target)->run($migrations, self::SEEDED, false);
        $books = (int) $target->query('SELECT COUNT(*) FROM books')->fetchColumn();
        $this->assertThrows(RuntimeException::class, fn () => $this->importer($source, $target)->run($migrations, self::SEEDED, false));
        $this->assertSame($books, (int) $target->query('SELECT COUNT(*) FROM books')->fetchColumn(), 'nothing merged');
    }

    public function testProductionGuardRefusesTheTestDatabaseByDefault(): void
    {
        [$source, $target, $migrations] = $this->databases();
        $this->assertThrows(RuntimeException::class, fn () => (new SqliteToMysqlImporter($source, $target))->run($migrations, self::SEEDED, true));
    }

    public function testNumbersCompareByValueEverythingElseStrictly(): void
    {
        $this->assertTrue(SqliteToMysqlImporter::same(4.5, '4.50'));
        $this->assertTrue(SqliteToMysqlImporter::same(3, '3'));
        $this->assertTrue(SqliteToMysqlImporter::same(null, null));
        $this->assertFalse(SqliteToMysqlImporter::same(null, ''));
        $this->assertFalse(SqliteToMysqlImporter::same('2026-11-10 11:28:01', '2026-11-10 11:28:02'));
        $this->assertFalse(SqliteToMysqlImporter::same('رُكون', 'ركون'), 'diacritics are significant');
    }
}
