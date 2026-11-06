<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Core\Database;
use App\Core\HttpException;
use App\Models\ActivityLog;
use App\Services\Storage\PrivateStorage;

/**
 * Stores a validated PDF as a NEW file version and queues its rendering.
 *
 * Version safety (spec §8.3, §9.3): the new version is NOT activated here.
 * books.current_file_id only switches after every page has been rendered
 * (RenderService::activate). Earlier versions and their images are kept.
 */
final class PdfUploadService
{
    /**
     * @param bool $fromHttpUpload true → move_uploaded_file() (only genuine PHP uploads accepted)
     * @return array{0: ?int, 1: list<string>} [new book_files.id | null, errors]
     */
    public static function store(int $bookId, ?string $tmpPath, string $originalName, int $uploadError, int $adminId, ?string $ip, bool $fromHttpUpload = true): array
    {
        $book = Database::first('SELECT id, title, current_file_id FROM books WHERE id = :id AND deleted_at IS NULL', ['id' => $bookId]);
        if (!$book) {
            throw HttpException::notFound();
        }
        if ($fromHttpUpload && $uploadError === UPLOAD_ERR_OK && ($tmpPath === null || !is_uploaded_file($tmpPath))) {
            return [null, [__('pdf.upload_failed')]];
        }
        $v = PdfValidator::validate($tmpPath, $originalName, $uploadError);
        if (!$v['ok']) {
            return [null, $v['errors']];
        }
        // Same bytes as an existing version of this book → nothing to do.
        if (Database::scalar('SELECT id FROM book_files WHERE book_id = :b AND checksum_sha256 = :s', ['b' => $bookId, 's' => $v['sha256']])) {
            return [null, [__('pdf.duplicate')]];
        }
        if (PrivateStorage::freeMb() < (int) config('rendering.min_free_disk_mb', 500)) {
            return [null, [__('pdf.no_space')]];
        }

        $relative = PrivateStorage::ORIGINALS . '/' . $bookId . '/' . PrivateStorage::randomName('pdf');
        PrivateStorage::moveInto((string) $tmpPath, $relative, $fromHttpUpload);

        try {
            $fileId = Database::transaction(static function () use ($bookId, $relative, $originalName, $v, $adminId, $ip, $book): int {
                $version = 1 + (int) Database::scalar('SELECT COALESCE(MAX(version_number), 0) FROM book_files WHERE book_id = :b', ['b' => $bookId]);
                $now = gmdate('Y-m-d H:i:s');
                Database::query(
                    "INSERT INTO book_files (book_id, storage_path, original_name, mime_type, file_size, checksum_sha256, version_number,
                                             validation_status, render_status, render_engine, render_profile, page_count, uploaded_by, created_at)
                     VALUES (:b, :p, :n, 'application/pdf', :size, :sha, :v, 'valid', 'pending', 'poppler', :profile, :pages, :by, :c)",
                    ['b' => $bookId, 'p' => $relative, 'n' => mb_substr(basename($originalName), 0, 255), 'size' => $v['size'], 'sha' => $v['sha256'],
                     'v' => $version, 'profile' => 'webp-w' . (int) config('rendering.page_width', 1400), 'pages' => $v['pages'], 'by' => $adminId, 'c' => $now]
                );
                $fileId = Database::lastInsertId();
                Database::query("INSERT INTO book_render_jobs (book_file_id, status, attempts, created_at) VALUES (:f, 'queued', 0, :c)", ['f' => $fileId, 'c' => $now]);
                ActivityLog::record($adminId, 'book.file_uploaded', 'book', $bookId, $book['title'] . ' — v' . $version . ' (' . $v['pages'] . ')', $ip);
                return $fileId;
            });
        } catch (\Throwable $e) {
            @unlink(PrivateStorage::path($relative)); // no orphan original if the DB write failed
            throw $e;
        }
        return [$fileId, []];
    }
}
