<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/** Member reading progress in MySQL (spec §7.3, FR-010). One row per user and book. */
final class ReadingProgressService
{
    /** @return array{page:int,total:int,percent:float,file_version:int} */
    public static function save(int $userId, array $book, array $file, int $page, int $fileVersion): array
    {
        if ($fileVersion !== $file['version']) {
            throw new HttpException(409, __('reader.version_changed'));
        }
        if ($page < 1 || $page > $file['pages']) {
            throw new HttpException(422, __('validation.invalid'));
        }
        $percent = round($page / $file['pages'] * 100, 2);
        $now = gmdate('Y-m-d H:i:s');
        $params = ['u' => $userId, 'b' => $book['id'], 'f' => $file['id'], 'p' => $page, 't' => $file['pages'], 'pc' => $percent, 'r' => $now, 'up' => $now];

        $exists = Database::scalar('SELECT id FROM reading_progress WHERE user_id = :u AND book_id = :b', ['u' => $userId, 'b' => $book['id']]);
        if ($exists) {
            Database::query('UPDATE reading_progress SET book_file_id = :f, current_page = :p, total_pages = :t, progress_percent = :pc, last_read_at = :r, updated_at = :up
                              WHERE user_id = :u AND book_id = :b', $params);
        } else {
            try {
                Database::query('INSERT INTO reading_progress (user_id, book_id, book_file_id, current_page, total_pages, progress_percent, last_read_at, updated_at)
                                 VALUES (:u, :b, :f, :p, :t, :pc, :r, :up)', $params);
            } catch (\PDOException) {
                // Concurrent first save (unique user_id+book_id): fall back to update.
                Database::query('UPDATE reading_progress SET book_file_id = :f, current_page = :p, total_pages = :t, progress_percent = :pc, last_read_at = :r, updated_at = :up
                                  WHERE user_id = :u AND book_id = :b', $params);
            }
        }
        return ['page' => $page, 'total' => $file['pages'], 'percent' => $percent, 'file_version' => $file['version']];
    }

    /** @return array{page:int,total:int,percent:float,file_version:int|null,last_read_at:string}|null */
    public static function get(int $userId, int $bookId): ?array
    {
        $r = Database::first(
            'SELECT p.current_page, p.total_pages, p.progress_percent, p.last_read_at, f.version_number
               FROM reading_progress p LEFT JOIN book_files f ON f.id = p.book_file_id
              WHERE p.user_id = :u AND p.book_id = :b',
            ['u' => $userId, 'b' => $bookId]
        );
        return $r ? ['page' => (int) $r['current_page'], 'total' => (int) $r['total_pages'], 'percent' => (float) $r['progress_percent'],
            'file_version' => $r['version_number'] !== null ? (int) $r['version_number'] : null, 'last_read_at' => (string) $r['last_read_at']] : null;
    }
}
