<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Admin view of a book's PDF versions and their render jobs. Never used by public pages. */
final class BookFile
{
    /** @return list<array<string,mixed>> newest first */
    public static function versionsFor(int $bookId): array
    {
        return Database::all(
            "SELECT f.id, f.version_number, f.file_size, f.page_count, f.validation_status, f.render_status, f.created_at, f.rendered_at,
                    j.status AS job_status, j.attempts, j.pages_done, j.last_error
               FROM book_files f
               LEFT JOIN book_render_jobs j ON j.id = (SELECT MAX(j2.id) FROM book_render_jobs j2 WHERE j2.book_file_id = f.id)
              WHERE f.book_id = :b
              ORDER BY f.version_number DESC",
            ['b' => $bookId]
        );
    }
}
