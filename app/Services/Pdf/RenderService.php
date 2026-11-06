<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Models\ActivityLog;
use App\Services\Storage\PrivateStorage;
use RuntimeException;
use Throwable;

/**
 * PDF → WebP page pipeline (spec §7.5, FR-023, TC-028).
 *
 *  claim job (atomic) → render in batches into a private temp dir → GD → WebP
 *  masters + thumbnails in private storage → verify EVERY page exists →
 *  mark file ready → activate as books.current_file_id (single transaction).
 *
 * Any failure: partial images and rows for that version are removed, the job
 * is re-queued (up to max_attempts) or marked failed, and the book keeps its
 * previous version. An incomplete version can therefore never become current.
 * Masters are stored UNWATERMARKED in private storage; the watermark is baked
 * in per request by PageDeliveryService.
 */
final class RenderService
{
    public function __construct(private readonly Rasterizer $rasterizer = new PopplerRasterizer())
    {
    }

    /** Re-queue jobs stuck in "processing" (crashed worker). */
    public static function reclaimStale(): int
    {
        $before = gmdate('Y-m-d H:i:s', time() - (int) Config::get('rendering.stale_after', 1800));
        return Database::query(
            "UPDATE book_render_jobs SET status = 'queued', locked_by = NULL WHERE status = 'processing' AND started_at < :t",
            ['t' => $before]
        )->rowCount();
    }

    /** Atomically claim the oldest queued job. @return int|null job id */
    public static function claimNext(string $worker): ?int
    {
        for ($i = 0; $i < 5; $i++) {
            $id = Database::scalar("SELECT id FROM book_render_jobs WHERE status = 'queued' ORDER BY created_at ASC, id ASC LIMIT 1");
            if (!$id) {
                return null;
            }
            if (self::claim((int) $id, $worker)) {
                return (int) $id;
            }
        }
        return null;
    }

    public static function claim(int $jobId, string $worker): bool
    {
        // The WHERE status='queued' makes the claim atomic across concurrent workers.
        return Database::query(
            "UPDATE book_render_jobs SET status = 'processing', locked_by = :w, started_at = :t, attempts = attempts + 1, last_error = NULL
              WHERE id = :id AND status = 'queued'",
            ['w' => substr($worker, 0, 64), 't' => gmdate('Y-m-d H:i:s'), 'id' => $jobId]
        )->rowCount() === 1;
    }

    /** @return array{ok:bool, pages:int, error:?string} */
    public function process(int $jobId): array
    {
        $job = Database::first('SELECT j.*, f.book_id, f.storage_path, f.page_count, f.version_number FROM book_render_jobs j JOIN book_files f ON f.id = j.book_file_id WHERE j.id = :id', ['id' => $jobId]);
        if (!$job || $job['status'] !== 'processing') {
            throw new RuntimeException('Job is not claimed for processing.');
        }
        $fileId = (int) $job['book_file_id'];
        $pages = (int) $job['page_count'];
        $tmpRel = PrivateStorage::TEMP . '/render-' . $fileId . '-' . bin2hex(random_bytes(4));

        Database::query("UPDATE book_files SET render_status = 'processing' WHERE id = :f", ['f' => $fileId]);
        try {
            if (PrivateStorage::freeMb() < (int) Config::get('rendering.min_free_disk_mb', 500)) {
                throw new RuntimeException('Not enough free disk space.');
            }
            $pdf = PrivateStorage::path((string) $job['storage_path']);
            if (!is_file($pdf)) {
                throw new RuntimeException('Original file is missing from private storage.');
            }
            // Re-check the page count on the stored file (it is the source of truth).
            $info = PdfInspector::inspect($pdf);
            if ($info['pages'] !== $pages || $pages < 1) {
                throw new RuntimeException('Page count mismatch.');
            }
            $this->clearOutputs($fileId); // idempotent retry
            $tmpAbs = PrivateStorage::ensureDir($tmpRel);
            $pagesDir = PrivateStorage::PAGES . '/' . $fileId;
            $thumbsDir = PrivateStorage::THUMBS . '/' . $fileId;
            PrivateStorage::ensureDir($pagesDir);
            PrivateStorage::ensureDir($thumbsDir);

            $width = (int) Config::get('rendering.page_width', 1400);
            $batch = max(1, (int) Config::get('rendering.batch_pages', 10));
            for ($from = 1; $from <= $pages; $from += $batch) {
                $to = min($pages, $from + $batch - 1);
                $pngs = $this->rasterizer->rasterize($pdf, $from, $to, $tmpAbs, $width);
                foreach ($pngs as $n => $png) {
                    $this->storePage($fileId, $n, $png, $pagesDir, $thumbsDir);
                    @unlink($png);
                }
                Database::query('UPDATE book_render_jobs SET pages_done = :d WHERE id = :id', ['d' => $to, 'id' => $jobId]);
            }

            // Completeness gate: every page must exist as page + thumbnail.
            $count = static fn (string $variant) => (int) Database::scalar(
                'SELECT COUNT(DISTINCT page_number) FROM book_page_renders WHERE book_file_id = :f AND variant = :v AND page_number BETWEEN 1 AND :n',
                ['f' => $fileId, 'v' => $variant, 'n' => $pages]
            );
            if ($count('page') !== $pages || $count('thumb') !== $pages) {
                throw new RuntimeException('Incomplete rendering.');
            }

            self::activate($fileId, $jobId, $this->rasterizer->name());
            return ['ok' => true, 'pages' => $pages, 'error' => null];
        } catch (Throwable $e) {
            $this->fail($jobId, $fileId, $e);
            return ['ok' => false, 'pages' => 0, 'error' => self::safeError($e)];
        } finally {
            PrivateStorage::deleteDir($tmpRel);
        }
    }

    /** Mark ready and switch the book to this version — only now, atomically. */
    public static function activate(int $fileId, int $jobId, string $engine): void
    {
        Database::transaction(static function () use ($fileId, $jobId, $engine): void {
            $now = gmdate('Y-m-d H:i:s');
            $file = Database::first('SELECT book_id, page_count, version_number FROM book_files WHERE id = :f', ['f' => $fileId]);
            Database::query("UPDATE book_files SET render_status = 'ready', render_engine = :e, rendered_at = :t WHERE id = :f", ['e' => $engine, 't' => $now, 'f' => $fileId]);
            Database::query("UPDATE book_render_jobs SET status = 'completed', finished_at = :t, locked_by = NULL WHERE id = :id", ['t' => $now, 'id' => $jobId]);
            // Never downgrade: only activate if newer than the current version.
            $current = Database::first('SELECT f.version_number FROM books b LEFT JOIN book_files f ON f.id = b.current_file_id WHERE b.id = :b', ['b' => $file['book_id']]);
            if ($current === null || $current['version_number'] === null || (int) $current['version_number'] < (int) $file['version_number']) {
                Database::query('UPDATE books SET current_file_id = :f, page_count = :p, updated_at = :t WHERE id = :b',
                    ['f' => $fileId, 'p' => (int) $file['page_count'], 't' => $now, 'b' => $file['book_id']]);
            }
        });
    }

    private function storePage(int $fileId, int $n, string $png, string $pagesDir, string $thumbsDir): void
    {
        $img = @imagecreatefrompng($png);
        if (!$img) {
            throw new RuntimeException("Page $n could not be decoded.");
        }
        try {
            $quality = (int) Config::get('rendering.quality', 82);
            $name = sprintf('p%04d.webp', $n);
            $this->writeVariant($fileId, 'page', $n, $img, "$pagesDir/$name", $quality);

            $tw = (int) Config::get('rendering.thumbnail_width', 220);
            $th = max(1, (int) round(imagesy($img) * $tw / max(1, imagesx($img))));
            $thumb = imagecreatetruecolor($tw, $th);
            imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, imagesx($img), imagesy($img));
            $this->writeVariant($fileId, 'thumb', $n, $thumb, "$thumbsDir/" . sprintf('t%04d.webp', $n), 70);
            imagedestroy($thumb);
        } finally {
            imagedestroy($img);
        }
    }

    private function writeVariant(int $fileId, string $variant, int $n, \GdImage $img, string $relative, int $quality): void
    {
        $abs = PrivateStorage::path($relative);
        if (!imagewebp($img, $abs, $quality)) {
            throw new RuntimeException("Could not write page $n.");
        }
        @chmod($abs, 0640);
        Database::query(
            'INSERT INTO book_page_renders (book_file_id, variant, page_number, storage_path, format, width, height, byte_size, checksum_sha256, created_at)
             VALUES (:f, :v, :n, :p, :fmt, :w, :h, :b, :sha, :c)',
            ['f' => $fileId, 'v' => $variant, 'n' => $n, 'p' => $relative, 'fmt' => 'webp', 'w' => imagesx($img), 'h' => imagesy($img),
             'b' => (int) filesize($abs), 'sha' => (string) hash_file('sha256', $abs), 'c' => gmdate('Y-m-d H:i:s')]
        );
    }

    private function clearOutputs(int $fileId): void
    {
        Database::query('DELETE FROM book_page_renders WHERE book_file_id = :f', ['f' => $fileId]);
        PrivateStorage::deleteDir(PrivateStorage::PAGES . '/' . $fileId);
        PrivateStorage::deleteDir(PrivateStorage::THUMBS . '/' . $fileId);
    }

    private function fail(int $jobId, int $fileId, Throwable $e): void
    {
        Logger::warning('Render job failed', ['job' => $jobId, 'file' => $fileId, 'error' => $e->getMessage()]);
        try {
            $this->clearOutputs($fileId); // never leave a half-rendered version behind
        } catch (Throwable) {
        }
        $attempts = (int) Database::scalar('SELECT attempts FROM book_render_jobs WHERE id = :id', ['id' => $jobId]);
        $final = $attempts >= (int) Config::get('rendering.max_attempts', 3);
        Database::query(
            'UPDATE book_render_jobs SET status = :s, finished_at = :t, locked_by = NULL, last_error = :e WHERE id = :id',
            ['s' => $final ? 'failed' : 'queued', 't' => gmdate('Y-m-d H:i:s'), 'e' => self::safeError($e), 'id' => $jobId]
        );
        Database::query('UPDATE book_files SET render_status = :s WHERE id = :f', ['s' => $final ? 'failed' : 'pending', 'f' => $fileId]);
    }

    /** Error text safe to show to admins: no filesystem paths. */
    public static function safeError(Throwable $e): string
    {
        $msg = (string) preg_replace('#(/[^\s:]+)+#', '[path]', $e->getMessage());
        return mb_substr($msg, 0, 500);
    }

    /** Admin "retry": re-queue a failed job with a fresh attempt budget. */
    public static function retry(int $fileId, int $adminId, ?string $ip): bool
    {
        $ok = Database::query(
            "UPDATE book_render_jobs SET status = 'queued', attempts = 0, last_error = NULL WHERE book_file_id = :f AND status = 'failed'",
            ['f' => $fileId]
        )->rowCount() > 0;
        if ($ok) {
            Database::query("UPDATE book_files SET render_status = 'pending' WHERE id = :f", ['f' => $fileId]);
            $bookId = (int) Database::scalar('SELECT book_id FROM book_files WHERE id = :f', ['f' => $fileId]);
            ActivityLog::record($adminId, 'book.render_retried', 'book', $bookId, 'file #' . $fileId, $ip);
        }
        return $ok;
    }

    /**
     * Queue re-rendering of the CURRENT version of books whose page images are
     * missing (after a files restore or a storage move), or of all books with
     * $all. The original PDF must exist. Already queued/processing files are skipped.
     *
     * @return list<int> book_files ids queued
     */
    public static function queueRebuild(bool $all = false, ?int $bookId = null): array
    {
        $rows = Database::all(
            'SELECT f.id, f.storage_path, f.page_count FROM books b JOIN book_files f ON f.id = b.current_file_id
              WHERE b.deleted_at IS NULL' . ($bookId ? ' AND b.id = :b' : ''),
            $bookId ? ['b' => $bookId] : []
        );
        $queued = [];
        foreach ($rows as $f) {
            $fileId = (int) $f['id'];
            if (Database::scalar("SELECT 1 FROM book_render_jobs WHERE book_file_id = :f AND status IN ('queued','processing')", ['f' => $fileId])) {
                continue;
            }
            if (!is_file(PrivateStorage::path((string) $f['storage_path']))) {
                continue; // nothing to render from; reported by the caller
            }
            if (!$all && self::outputsComplete($fileId, (int) $f['page_count'])) {
                continue;
            }
            Database::query("INSERT INTO book_render_jobs (book_file_id, status, attempts, created_at) VALUES (:f, 'queued', 0, :c)", ['f' => $fileId, 'c' => gmdate('Y-m-d H:i:s')]);
            $queued[] = $fileId;
        }
        return $queued;
    }

    /** Every page + thumbnail row exists AND its file is present on disk. */
    public static function outputsComplete(int $fileId, int $pages): bool
    {
        $rows = Database::all('SELECT storage_path FROM book_page_renders WHERE book_file_id = :f', ['f' => $fileId]);
        if (count($rows) < $pages * 2) {
            return false;
        }
        foreach ($rows as $r) {
            if (!is_file(PrivateStorage::path((string) $r['storage_path']))) {
                return false;
            }
        }
        return true;
    }
}
