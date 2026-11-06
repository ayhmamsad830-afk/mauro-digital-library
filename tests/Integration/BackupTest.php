<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Services\BackupService;
use Tests\Support\TestStorage;
use Tests\TestCase;

/** Backups (Phase 7). The MySQL dump itself is BLOCKED until a MySQL server is provided. */
final class BackupTest extends TestCase
{
    private string $root = '';
    private array $saved = [];

    public function setUp(): void
    {
        $this->root = TestStorage::create();
        foreach (['storage.backups', 'database.driver', 'database.username'] as $k) {
            $this->saved[$k] = Config::get($k);
        }
        Config::set('storage.backups', $this->root . '/backups');
    }

    public function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            Config::set($k, $v);
        }
        TestStorage::destroy($this->root);
    }

    public function testFilesBackupContainsOnlyIrreplaceableFilesAndVerifies(): void
    {
        mkdir($this->root . '/private/covers', 0750, true);
        file_put_contents($this->root . '/private/original-pdf/a.pdf', '%PDF-1.4 original');
        file_put_contents($this->root . '/private/covers/c.webp', 'RIFFxxxxWEBP');
        mkdir($this->root . '/private/rendered-pages/1', 0750, true);
        file_put_contents($this->root . '/private/rendered-pages/1/p1.webp', 'regenerable');

        $archive = BackupService::files('20261106-000000');
        $this->assertSame($this->root . '/backups/files-20261106-000000.tar.gz', $archive);
        $this->assertTrue(is_file("$archive.sha256"));
        $this->assertSame(['ok' => true, 'detail' => '2 files'], BackupService::verify($archive));
        $tar = \App\Services\ProcessRunner::which('tar');
        $this->assertTrue($tar !== null, 'tar is required to list the archive');
        // Relative archive name: GNU tar (e.g. Git for Windows) reads "C:/…" as a remote host.
        $list = \App\Services\ProcessRunner::run([$tar, '-tzf', basename($archive)], 60, dirname($archive))['stdout'];
        $this->assertContains('private/original-pdf/a.pdf', $list);
        $this->assertContains('private/covers/c.webp', $list);
        $this->assertNotContains('rendered-pages', $list, 'rendered pages are regenerated, not backed up');
        if (PHP_OS_FAMILY !== 'Windows') { // NTFS has no POSIX mode bits; chmod() is a no-op there
            $this->assertSame('640', sprintf('%o', fileperms($archive) & 0777));
        }

        file_put_contents($archive, 'x', FILE_APPEND);
        $this->assertSame(['ok' => false, 'detail' => 'checksum mismatch'], BackupService::verify($archive));
    }

    public function testSqlDumpCompletenessCheckAndPruning(): void
    {
        $dir = BackupService::dir();
        $good = "$dir/db-20261101-000000.sql.gz";
        $bad = "$dir/db-20261102-000000.sql.gz";
        file_put_contents($good, gzencode("CREATE TABLE x (id int);\n-- Dump completed on 2026-11-01\n"));
        file_put_contents($bad, gzencode("CREATE TABLE x (id int);\nINSERT INTO x VALUES (1"));
        $this->assertTrue(BackupService::verify($good)['ok']);
        $this->assertSame('dump not completed', BackupService::verify($bad)['detail']);
        $this->assertSame('missing', BackupService::verify("$dir/nope.sql.gz")['detail']);

        touch($good, time() - 20 * 86400);
        $this->assertSame(['db-20261101-000000.sql.gz'], BackupService::prune(14));
        $this->assertFalse(is_file($good));
        $this->assertTrue(is_file($bad), 'recent backup kept');
    }

    public function testDatabaseBackupRefusesWithoutMysqlInsteadOfPretending(): void
    {
        Config::set('database.driver', 'mysql');
        Config::set('database.username', '');
        try {
            BackupService::database();
            $this->assertTrue(false, 'should refuse');
        } catch (\RuntimeException $e) {
            $this->assertContains('No MySQL database configured', $e->getMessage());
        }
        $this->assertSame([], glob(BackupService::dir() . '/db-*') ?: []);
    }
}
