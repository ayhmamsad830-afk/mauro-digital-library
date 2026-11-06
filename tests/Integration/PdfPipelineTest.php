<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Services\Pdf\PdfUploadService;
use App\Services\Pdf\PdfValidator;
use App\Services\Pdf\PopplerRasterizer;
use App\Services\Pdf\Rasterizer;
use App\Services\Pdf\RenderService;
use App\Services\Storage\PrivateStorage;
use RuntimeException;
use Tests\Support\LibraryFixtures;
use Tests\Support\PdfFactory;
use Tests\Support\TestDatabase;
use Tests\Support\TestStorage;
use Tests\TestCase;

/**
 * Upload validation, private storage, server-side rendering (real Poppler + GD),
 * completeness/failure handling and version activation. DB: SQLite harness.
 */
final class PdfPipelineTest extends TestCase
{
    private array $ids = [];
    private string $root = '';
    private string $work = '';
    private array $saved = [];

    public function setUp(): void
    {
        TestDatabase::sqlite();
        $this->ids = LibraryFixtures::load();
        $this->root = TestStorage::create();
        $this->work = $this->root . '/work';
        mkdir($this->work, 0750, true);
        foreach (['storage.pdf_max_bytes', 'rendering.max_attempts', 'rendering.batch_pages', 'rendering.page_width'] as $k) {
            $this->saved[$k] = Config::get($k);
        }
        Config::set('rendering.batch_pages', 2);
        Config::set('rendering.page_width', 600); // keep tests fast
    }

    public function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            Config::set($k, $v);
        }
        TestStorage::destroy($this->root);
        TestDatabase::release();
    }

    private function pdf(int $pages = 3, string $name = 'book.pdf', string $label = 'MAURO TEST'): string
    {
        return PdfFactory::write($this->work, bin2hex(random_bytes(3)) . '-' . $name, PdfFactory::make($pages, $label));
    }

    /** Store via the real upload service (not an HTTP upload in this unit context). */
    private function upload(int $bookId, string $path, string $originalName = 'My Secret Name.pdf'): array
    {
        return PdfUploadService::store($bookId, $path, $originalName, UPLOAD_ERR_OK, $this->ids['u_admin'], '127.0.0.1', false);
    }

    private function jobFor(int $fileId): int
    {
        return (int) Database::scalar('SELECT id FROM book_render_jobs WHERE book_file_id = :f ORDER BY id DESC LIMIT 1', ['f' => $fileId]);
    }

    private function render(int $fileId, ?Rasterizer $r = null): array
    {
        $job = $this->jobFor($fileId);
        $this->assertTrue(RenderService::claim($job, 'test'), 'job claimable');
        return (new RenderService($r ?? new PopplerRasterizer()))->process($job);
    }

    private function current(int $bookId): ?int
    {
        $v = Database::scalar('SELECT current_file_id FROM books WHERE id = :b', ['b' => $bookId]);
        return $v === null ? null : (int) $v;
    }

    // ------------------------------------------------------------ validation

    public function testValidPdfIsAccepted(): void
    {
        $v = PdfValidator::validate($this->pdf(3), 'Book.PDF');
        $this->assertTrue($v['ok'], implode(' ', $v['errors']));
        $this->assertSame(3, $v['pages']);
        $this->assertSame(64, strlen($v['sha256']));
    }

    public function testFakeExtensionsAndNonPdfContentAreRejected(): void
    {
        $png = PdfFactory::write($this->work, 'image.pdf', PdfFactory::png());
        $this->assertSame([__('pdf.not_pdf')], PdfValidator::validate($png, 'image.pdf')['errors'], 'PNG renamed to .pdf');
        $txt = PdfFactory::write($this->work, 'notes.pdf', "%PDX not really\nhello");
        $this->assertSame([__('pdf.not_pdf')], PdfValidator::validate($txt, 'notes.pdf')['errors'], 'text renamed to .pdf');
        $html = PdfFactory::write($this->work, 'page.pdf', '<html><script>alert(1)</script></html>');
        $this->assertFalse(PdfValidator::validate($html, 'page.pdf')['ok']);
        $this->assertSame([__('pdf.bad_extension')], PdfValidator::validate($this->pdf(1), 'book.png')['errors'], 'real PDF with wrong extension');
        $this->assertSame([__('pdf.bad_extension')], PdfValidator::validate($this->pdf(1), 'book.pdf.php')['errors'], 'double extension');
    }

    public function testOversizedEmptyAndUploadErrors(): void
    {
        Config::set('storage.pdf_max_bytes', 200);
        $this->assertSame([__('pdf.too_large', ['max' => 0.0])], PdfValidator::validate($this->pdf(2), 'a.pdf')['errors']);
        Config::set('storage.pdf_max_bytes', 10 * 1048576);
        $this->assertSame([__('pdf.too_large', ['max' => 10.0])], PdfValidator::validate(null, 'a.pdf', UPLOAD_ERR_INI_SIZE)['errors']);
        $this->assertSame([__('pdf.no_file')], PdfValidator::validate(null, '', UPLOAD_ERR_NO_FILE)['errors']);
        $this->assertSame([__('pdf.partial')], PdfValidator::validate(null, 'a.pdf', UPLOAD_ERR_PARTIAL)['errors']);
        $empty = PdfFactory::write($this->work, 'empty.pdf', '');
        $this->assertSame([__('pdf.empty')], PdfValidator::validate($empty, 'empty.pdf')['errors']);
    }

    public function testCorruptAndEncryptedPdfsAreRejected(): void
    {
        $bad = PdfFactory::write($this->work, 'bad.pdf', PdfFactory::corrupt());
        $this->assertSame([__('pdf.unreadable')], PdfValidator::validate($bad, 'bad.pdf')['errors']);
        $truncated = PdfFactory::write($this->work, 'trunc.pdf', substr(PdfFactory::make(3), 0, 300));
        $this->assertFalse(PdfValidator::validate($truncated, 'trunc.pdf')['ok']);
        $enc = PdfFactory::encrypted($this->work);
        if ($enc === null) {
            $this->markSkipped('qpdf not available to build an encrypted PDF');
        }
        $this->assertSame([__('pdf.encrypted')], PdfValidator::validate($enc, 'enc.pdf')['errors']);
    }

    public function testTooManyPagesIsRejected(): void
    {
        Config::set('rendering.max_pages', 2);
        try {
            $this->assertSame([__('pdf.too_many_pages', ['max' => 2])], PdfValidator::validate($this->pdf(3), 'a.pdf')['errors']);
        } finally {
            Config::set('rendering.max_pages', 2000);
        }
    }

    // --------------------------------------------------------------- storage

    public function testUploadStoresPrivatelyWithRandomNameAndQueuesWithoutActivating(): void
    {
        $book = $this->ids['b2'];
        [$fileId, $errors] = $this->upload($book, $this->pdf(3));
        $this->assertSame([], $errors);
        $row = Database::first('SELECT * FROM book_files WHERE id = :f', ['f' => $fileId]);
        $this->assertTrue((bool) preg_match('#^private/original-pdf/' . $book . '/[0-9a-f]{32}\.pdf$#', $row['storage_path']), $row['storage_path']);
        $this->assertFalse(str_contains($row['storage_path'], 'Secret'), 'original name never used on disk');
        $abs = PrivateStorage::path($row['storage_path']);
        $this->assertTrue(is_file($abs));
        $this->assertSame('640', sprintf('%o', fileperms($abs) & 0777));
        $this->assertFalse(str_starts_with(realpath($abs), realpath(BASE_PATH . '/public')), 'outside the web root');
        $this->assertSame(['valid', 'pending', 3, 1], [$row['validation_status'], $row['render_status'], (int) $row['page_count'], (int) $row['version_number']]);
        $this->assertSame('queued', Database::scalar('SELECT status FROM book_render_jobs WHERE book_file_id = :f', ['f' => $fileId]));
        $this->assertSame(null, $this->current($book), 'not active before rendering');
        $this->assertSame(1, (int) Database::scalar("SELECT COUNT(*) FROM admin_activity_logs WHERE action = 'book.file_uploaded'"));
    }

    public function testDuplicateAndNonHttpUploadsAreRejected(): void
    {
        $bytes = PdfFactory::make(2, 'DUP');
        $this->upload($this->ids['b2'], PdfFactory::write($this->work, 'a.pdf', $bytes));
        [$id, $errors] = $this->upload($this->ids['b2'], PdfFactory::write($this->work, 'b.pdf', $bytes));
        $this->assertSame(null, $id);
        $this->assertSame([__('pdf.duplicate')], $errors);
        // A path that did not arrive through PHP's upload mechanism is refused in HTTP mode.
        [$id2, $e2] = PdfUploadService::store($this->ids['b2'], $this->pdf(1), 'x.pdf', UPLOAD_ERR_OK, $this->ids['u_admin'], null, true);
        $this->assertSame(null, $id2);
        $this->assertSame([__('pdf.upload_failed')], $e2);
    }

    // ------------------------------------------------------------- rendering

    public function testRenderingProducesPrivateWebpPagesAndThumbnailsThenActivates(): void
    {
        $book = $this->ids['b2'];
        [$fileId] = $this->upload($book, $this->pdf(3));
        $r = $this->render($fileId);
        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame(3, $r['pages']);

        $rows = Database::all('SELECT variant, page_number, storage_path, width, height, format FROM book_page_renders WHERE book_file_id = :f ORDER BY variant, page_number', ['f' => $fileId]);
        $this->assertSame(6, count($rows));
        foreach ($rows as $row) {
            $abs = PrivateStorage::path($row['storage_path']);
            $img = imagecreatefromwebp($abs);
            $this->assertTrue($img !== false, 'valid WebP: ' . $row['storage_path']);
            $this->assertSame($row['variant'] === 'page' ? 600 : 220, imagesx($img));
            $this->assertTrue(imagesy($img) > imagesx($img), 'A4 portrait aspect kept');
            $this->assertSame('webp', $row['format']);
            $this->assertTrue(str_starts_with($row['storage_path'], $row['variant'] === 'page' ? 'private/rendered-pages/' : 'private/thumbnails/'));
        }
        $this->assertSame('ready', Database::scalar('SELECT render_status FROM book_files WHERE id = :f', ['f' => $fileId]));
        $this->assertSame('completed', Database::scalar('SELECT status FROM book_render_jobs WHERE book_file_id = :f', ['f' => $fileId]));
        $this->assertSame($fileId, $this->current($book));
        $this->assertSame(3, (int) Database::scalar('SELECT page_count FROM books WHERE id = :b', ['b' => $book]));
        $this->assertSame([], TestStorage::files($this->root, 'private/temp'), 'temp files cleaned');
        $this->assertSame(0, count(array_filter(TestStorage::files($this->root), fn ($f) => str_ends_with($f, '.png'))), 'no PNG leftovers');
    }

    public function testRenderingFailureRetriesThenFailsAndLeavesNothingBehind(): void
    {
        Config::set('rendering.max_attempts', 2);
        $book = $this->ids['b2'];
        [$fileId] = $this->upload($book, $this->pdf(3));
        // Damage the stored original after validation (e.g. disk corruption).
        file_put_contents(PrivateStorage::path((string) Database::scalar('SELECT storage_path FROM book_files WHERE id = :f', ['f' => $fileId])), PdfFactory::corrupt());

        $first = $this->render($fileId);
        $this->assertFalse($first['ok']);
        $this->assertSame('queued', Database::scalar('SELECT status FROM book_render_jobs WHERE book_file_id = :f', ['f' => $fileId]), 'retry scheduled');
        $this->assertFalse(str_contains((string) $first['error'], $this->root), 'no filesystem paths in errors');

        $second = $this->render($fileId);
        $this->assertFalse($second['ok']);
        $this->assertSame('failed', Database::scalar('SELECT status FROM book_render_jobs WHERE book_file_id = :f', ['f' => $fileId]));
        $this->assertSame('failed', Database::scalar('SELECT render_status FROM book_files WHERE id = :f', ['f' => $fileId]));
        $this->assertSame(0, (int) Database::scalar('SELECT COUNT(*) FROM book_page_renders WHERE book_file_id = :f', ['f' => $fileId]));
        $this->assertSame(null, $this->current($book));
        $this->assertFalse(is_dir($this->root . "/private/rendered-pages/$fileId"));

        // Admin retry re-queues with a fresh attempt budget.
        $this->assertTrue(RenderService::retry($fileId, $this->ids['u_admin'], null));
        $this->assertSame('queued', Database::scalar('SELECT status FROM book_render_jobs WHERE book_file_id = :f', ['f' => $fileId]));
    }

    public function testIncompleteProcessingNeverActivatesAndKeepsPreviousVersion(): void
    {
        $book = $this->ids['b2'];
        [$v1] = $this->upload($book, $this->pdf(2, 'v1.pdf', 'VERSION ONE'));
        $this->assertTrue($this->render($v1)['ok']);
        $this->assertSame($v1, $this->current($book));

        // v2: the rasteriser dies after the first batch (pages 1-2 of 5).
        [$v2] = $this->upload($book, $this->pdf(5, 'v2.pdf', 'VERSION TWO'));
        $dying = new class () implements Rasterizer {
            private int $calls = 0;
            public function name(): string { return 'dying'; }
            public function rasterize(string $pdf, int $from, int $to, string $out, int $w): array
            {
                if (++$this->calls > 1) {
                    throw new RuntimeException('Simulated crash at ' . $out);
                }
                return (new PopplerRasterizer())->rasterize($pdf, $from, $to, $out, $w);
            }
        };
        $r = $this->render($v2, $dying);
        $this->assertFalse($r['ok']);
        $this->assertSame($v1, $this->current($book), 'previous version stays active');
        $this->assertSame(0, (int) Database::scalar('SELECT COUNT(*) FROM book_page_renders WHERE book_file_id = :f', ['f' => $v2]), 'partial pages removed');
        $this->assertSame(4, (int) Database::scalar('SELECT COUNT(*) FROM book_page_renders WHERE book_file_id = :f', ['f' => $v1]), 'v1 images untouched');

        // A rasteriser that silently skips a page is caught by the completeness gate.
        $skipping = new class () implements Rasterizer {
            public function name(): string { return 'skipping'; }
            public function rasterize(string $pdf, int $from, int $to, string $out, int $w): array
            {
                $pages = (new PopplerRasterizer())->rasterize($pdf, $from, $to, $out, $w);
                unset($pages[3]);
                return $pages;
            }
        };
        $r2 = $this->render($v2, $skipping);
        $this->assertFalse($r2['ok']);
        $this->assertSame('Incomplete rendering.', $r2['error']);
        $this->assertSame($v1, $this->current($book));
    }

    public function testReplacementActivatesOnlyAfterSuccessAndKeepsOldVersions(): void
    {
        $book = $this->ids['b2'];
        [$v1] = $this->upload($book, $this->pdf(2, 'v1.pdf', 'ONE'));
        $this->render($v1);
        [$v2] = $this->upload($book, $this->pdf(4, 'v2.pdf', 'TWO'));
        $this->assertSame($v1, $this->current($book), 'queued v2 is not active');
        $this->assertSame(2, (int) Database::scalar('SELECT version_number FROM book_files WHERE id = :f', ['f' => $v2]));
        $this->assertTrue($this->render($v2)['ok']);
        $this->assertSame($v2, $this->current($book));
        $this->assertSame(4, (int) Database::scalar('SELECT page_count FROM books WHERE id = :b', ['b' => $book]));
        $this->assertSame(4, (int) Database::scalar('SELECT COUNT(*) FROM book_page_renders WHERE book_file_id = :f', ['f' => $v1]), 'old version kept');
        $this->assertTrue(is_file(PrivateStorage::path((string) Database::scalar('SELECT storage_path FROM book_files WHERE id = :f', ['f' => $v1]))));
    }

    public function testOlderVersionFinishingLaterNeverDowngrades(): void
    {
        $book = $this->ids['b2'];
        [$v1] = $this->upload($book, $this->pdf(1, 'a.pdf', 'A'));
        [$v2] = $this->upload($book, $this->pdf(1, 'b.pdf', 'B'));
        $this->assertTrue($this->render($v2)['ok']);
        $this->assertTrue($this->render($v1)['ok']);
        $this->assertSame($v2, $this->current($book));
    }

    public function testPublishingRequiresAFullyProcessedVersion(): void
    {
        [$id] = \App\Services\Admin\BookService::create([
            'title' => 'Pipeline Book', 'language' => 'en', 'visibility' => 'public', 'rights_status' => 'owned', 'rights_source' => 'Owner',
            'author_ids' => [(string) $this->ids['a_nora']], 'category_ids' => [(string) $this->ids['c_lit']],
        ], $this->ids['u_admin'], null);
        [$fileId] = $this->upload($id, $this->pdf(2));
        $this->assertSame([__('admin.block_no_file')], \App\Services\Admin\BookService::publish($id, $this->ids['u_admin'], null), 'queued version is not current');
        $this->render($fileId);
        $this->assertSame([], \App\Services\Admin\BookService::publish($id, $this->ids['u_admin'], null));
        $this->assertSame('published', Database::scalar('SELECT status FROM books WHERE id = :b', ['b' => $id]));
    }

    public function testStaleJobsAreReclaimedAndClaimIsExclusive(): void
    {
        [$fileId] = $this->upload($this->ids['b2'], $this->pdf(1));
        $job = $this->jobFor($fileId);
        $this->assertTrue(RenderService::claim($job, 'w1'));
        $this->assertFalse(RenderService::claim($job, 'w2'), 'second worker cannot claim');
        Database::query("UPDATE book_render_jobs SET started_at = '2000-01-01 00:00:00' WHERE id = :j", ['j' => $job]);
        $this->assertSame(1, RenderService::reclaimStale());
        $this->assertSame($job, RenderService::claimNext('w3'));
    }
}
