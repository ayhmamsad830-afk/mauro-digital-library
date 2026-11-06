<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Services\Pdf\PdfUploadService;
use App\Services\Pdf\RenderService;
use App\Services\Storage\PrivateStorage;
use Tests\Support\LibraryFixtures;
use Tests\Support\PdfFactory;
use Tests\Support\TestDatabase;
use Tests\Support\TestStorage;
use Tests\TestCase;

/** Recovery path after a files restore: missing page images are re-rendered from the originals. */
final class RenderRebuildTest extends TestCase
{
    private array $ids = [];
    private string $root = '';
    private mixed $width;

    public function setUp(): void
    {
        TestDatabase::sqlite();
        $this->ids = LibraryFixtures::load();
        $this->root = TestStorage::create();
        $this->width = Config::get('rendering.page_width');
        Config::set('rendering.page_width', 400);
    }

    public function tearDown(): void
    {
        Config::set('rendering.page_width', $this->width);
        TestStorage::destroy($this->root);
        TestDatabase::release();
    }

    private function runQueued(): void
    {
        while ($job = RenderService::claimNext('test')) {
            (new RenderService())->process($job);
        }
    }

    public function testMissingPagesAreQueuedAndRebuilt(): void
    {
        $pdf = PdfFactory::write($this->root . '/work', 'r.pdf', PdfFactory::make(3, 'REBUILD'));
        [$fileId] = PdfUploadService::store($this->ids['b1'], $pdf, 'r.pdf', UPLOAD_ERR_OK, $this->ids['u_admin'], null, false);
        $this->runQueued();
        $this->assertSame($fileId, (int) Database::scalar('SELECT current_file_id FROM books WHERE id = :b', ['b' => $this->ids['b1']]));
        $this->assertTrue(RenderService::outputsComplete($fileId, 3));
        $this->assertSame([], RenderService::queueRebuild(), 'nothing to do while complete');

        // Simulate a restore that brought back originals but not rendered pages.
        PrivateStorage::deleteDir(PrivateStorage::PAGES . '/' . $fileId);
        $this->assertFalse(RenderService::outputsComplete($fileId, 3));
        $this->assertSame([$fileId], RenderService::queueRebuild());
        $this->assertSame([], RenderService::queueRebuild(), 'already queued → not queued twice');
        $this->runQueued();
        $this->assertTrue(RenderService::outputsComplete($fileId, 3));
        $this->assertSame('ready', Database::scalar('SELECT render_status FROM book_files WHERE id = :f', ['f' => $fileId]));
        $this->assertSame($fileId, (int) Database::scalar('SELECT current_file_id FROM books WHERE id = :b', ['b' => $this->ids['b1']]), 'same version stays active');
        $this->assertSame(6, (int) Database::scalar('SELECT COUNT(*) FROM book_page_renders WHERE book_file_id = :f', ['f' => $fileId]), 'no duplicate render rows');

        $this->assertSame([$fileId], RenderService::queueRebuild(true, $this->ids['b1']), '--all forces a rebuild');
        $this->runQueued();
        // Original missing → cannot be queued.
        unlink(PrivateStorage::path((string) Database::scalar('SELECT storage_path FROM book_files WHERE id = :f', ['f' => $fileId])));
        PrivateStorage::deleteDir(PrivateStorage::PAGES . '/' . $fileId);
        $this->assertSame([], RenderService::queueRebuild());
    }
}
