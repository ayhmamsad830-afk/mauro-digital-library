<?php

declare(strict_types=1);

namespace App\Services\Member;

use App\Core\Database;
use App\Core\HttpException;
use App\Repositories\LibraryRepositoryFactory;
use App\Services\BookAccessPolicy;
use App\Services\RateLimiter;

/**
 * A member's personal library: favorites, bookmarks, reviews, history.
 * Every operation is scoped to the signed-in user's id (no IDOR) and only
 * touches books that this user is currently allowed to see.
 */
final class MemberLibrary
{
    /** Visible book for this viewer or 404. */
    public static function visibleBook(int $bookId): array
    {
        $book = LibraryRepositoryFactory::make()->findBookById($bookId);
        if ($book === null || !BookAccessPolicy::canView($book)) {
            throw HttpException::notFound();
        }
        return $book;
    }

    /** @param list<int> $ids @return list<array> books still visible, in the given order */
    public static function visibleBooks(array $ids): array
    {
        $repo = LibraryRepositoryFactory::make();
        $out = [];
        foreach (array_slice(array_values(array_unique($ids)), 0, 100) as $id) {
            $b = $repo->findBookById((int) $id);
            if ($b !== null && BookAccessPolicy::canView($b)) {
                $out[(int) $id] = $b;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ favorites

    public static function isFavorite(int $userId, int $bookId): bool
    {
        return (bool) Database::scalar('SELECT 1 FROM favorites WHERE user_id = :u AND book_id = :b', ['u' => $userId, 'b' => $bookId]);
    }

    /** @return bool new state (true = now a favorite) */
    public static function toggleFavorite(int $userId, int $bookId): bool
    {
        self::visibleBook($bookId);
        if (self::isFavorite($userId, $bookId)) {
            Database::query('DELETE FROM favorites WHERE user_id = :u AND book_id = :b', ['u' => $userId, 'b' => $bookId]);
            return false;
        }
        try {
            Database::query('INSERT INTO favorites (user_id, book_id, created_at) VALUES (:u, :b, :c)', ['u' => $userId, 'b' => $bookId, 'c' => gmdate('Y-m-d H:i:s')]);
        } catch (\PDOException) {
            // double submit: unique (user_id, book_id) already present
        }
        return true;
    }

    /** @return list<array> */
    public static function favorites(int $userId): array
    {
        $ids = Database::query('SELECT book_id FROM favorites WHERE user_id = :u ORDER BY created_at DESC, id DESC', ['u' => $userId])->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(self::visibleBooks(array_map('intval', $ids)));
    }

    // ------------------------------------------------------------ bookmarks

    public static function addBookmark(int $userId, int $bookId, int $page, ?string $title, ?string $note): int
    {
        $book = self::visibleBook($bookId);
        $pages = (int) ($book['page_count'] ?? 0);
        if ($page < 1 || ($pages > 0 && $page > $pages)) {
            throw new HttpException(422, __('validation.invalid'));
        }
        if ((int) Database::scalar('SELECT COUNT(*) FROM bookmarks WHERE user_id = :u AND book_id = :b', ['u' => $userId, 'b' => $bookId]) >= 200) {
            throw new HttpException(422, __('member.bookmark_limit'));
        }
        $now = gmdate('Y-m-d H:i:s');
        Database::query(
            'INSERT INTO bookmarks (user_id, book_id, page_number, title, note, created_at, updated_at) VALUES (:u, :b, :p, :t, :n, :c, :up)',
            ['u' => $userId, 'b' => $bookId, 'p' => $page, 't' => $title !== null ? mb_substr($title, 0, 255) : null, 'n' => $note !== null ? mb_substr($note, 0, 2000) : null, 'c' => $now, 'up' => $now]
        );
        return \App\Core\Database::lastInsertId();
    }

    /** Owner-only delete: another user's id simply matches nothing → 404. */
    public static function deleteBookmark(int $userId, int $bookmarkId): void
    {
        if (Database::query('DELETE FROM bookmarks WHERE id = :id AND user_id = :u', ['id' => $bookmarkId, 'u' => $userId])->rowCount() === 0) {
            throw HttpException::notFound();
        }
    }

    /** @return list<array{id:int,page:int,title:?string,note:?string,created_at:string,book:array}> */
    public static function bookmarks(int $userId, ?int $bookId = null): array
    {
        $rows = Database::all(
            'SELECT id, book_id, page_number, title, note, created_at FROM bookmarks WHERE user_id = :u'
            . ($bookId ? ' AND book_id = :b' : '') . ' ORDER BY created_at DESC, id DESC LIMIT 200',
            ['u' => $userId] + ($bookId ? ['b' => $bookId] : [])
        );
        $books = self::visibleBooks(array_map(static fn ($r) => (int) $r['book_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            if (isset($books[(int) $r['book_id']])) {
                $out[] = ['id' => (int) $r['id'], 'page' => (int) $r['page_number'], 'title' => $r['title'], 'note' => $r['note'], 'created_at' => $r['created_at'], 'book' => $books[(int) $r['book_id']]];
            }
        }
        return $out;
    }

    // --------------------------------------------------------------- history

    /** @return list<array{book:array,page:int,total:int,percent:float,last_read_at:string}> */
    public static function history(int $userId, int $limit = 50): array
    {
        $rows = Database::all(
            'SELECT book_id, current_page, total_pages, progress_percent, last_read_at FROM reading_progress WHERE user_id = :u ORDER BY last_read_at DESC, id DESC LIMIT :l',
            ['u' => $userId, 'l' => $limit]
        );
        $books = self::visibleBooks(array_map(static fn ($r) => (int) $r['book_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            if (isset($books[(int) $r['book_id']])) {
                $out[] = ['book' => $books[(int) $r['book_id']], 'page' => (int) $r['current_page'], 'total' => (int) $r['total_pages'],
                    'percent' => (float) $r['progress_percent'], 'last_read_at' => (string) $r['last_read_at']];
            }
        }
        return $out;
    }

    // --------------------------------------------------------------- reviews

    public static function myReview(int $userId, int $bookId): ?array
    {
        return Database::first('SELECT id, rating, comment, status, created_at, updated_at FROM reviews WHERE user_id = :u AND book_id = :b', ['u' => $userId, 'b' => $bookId]);
    }

    /** Create or update the user's single review for a book (spec FR-016). */
    public static function saveReview(int $userId, int $bookId, mixed $rating, mixed $comment): void
    {
        self::visibleBook($bookId);
        RateLimiter::enforce('reviews', "u:$userId");
        $rating = is_string($rating) && ctype_digit($rating) ? (int) $rating : (is_int($rating) ? $rating : 0);
        if ($rating < 1 || $rating > 5) {
            throw new HttpException(422, __('member.rating_invalid'));
        }
        $comment = is_string($comment) ? trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $comment))) : '';
        if (mb_strlen($comment) > 2000) {
            throw new HttpException(422, __('validation.max', ['max' => 2000]));
        }
        Database::transaction(static function () use ($userId, $bookId, $rating, $comment): void {
            $now = gmdate('Y-m-d H:i:s');
            if (self::myReview($userId, $bookId)) {
                // Editing keeps a moderator's "hidden" decision.
                Database::query('UPDATE reviews SET rating = :r, comment = :c, updated_at = :u WHERE user_id = :uid AND book_id = :b',
                    ['r' => $rating, 'c' => $comment !== '' ? $comment : null, 'u' => $now, 'uid' => $userId, 'b' => $bookId]);
            } else {
                Database::query("INSERT INTO reviews (user_id, book_id, rating, comment, status, created_at, updated_at) VALUES (:uid, :b, :r, :c, 'published', :t, :u)",
                    ['uid' => $userId, 'b' => $bookId, 'r' => $rating, 'c' => $comment !== '' ? $comment : null, 't' => $now, 'u' => $now]);
            }
            self::recomputeRating($bookId);
        });
    }

    public static function deleteReview(int $userId, int $bookId): void
    {
        Database::transaction(static function () use ($userId, $bookId): void {
            if (Database::query('DELETE FROM reviews WHERE user_id = :u AND book_id = :b', ['u' => $userId, 'b' => $bookId])->rowCount() === 0) {
                throw HttpException::notFound();
            }
            self::recomputeRating($bookId);
        });
    }

    /** books.rating_avg / rating_count from PUBLISHED reviews of ACTIVE users only. */
    public static function recomputeRating(int $bookId): void
    {
        $r = Database::first(
            "SELECT COUNT(*) AS n, COALESCE(AVG(r.rating), 0) AS avg FROM reviews r JOIN users u ON u.id = r.user_id
              WHERE r.book_id = :b AND r.status = 'published' AND u.status = 'active'",
            ['b' => $bookId]
        );
        Database::query('UPDATE books SET rating_avg = :a, rating_count = :n WHERE id = :b',
            ['a' => round((float) $r['avg'], 2), 'n' => (int) $r['n'], 'b' => $bookId]);
    }

    // ---------------------------------------------------- guest progress sync

    /**
     * Import guest (localStorage) progress after the member explicitly agrees.
     * Only fills books that have NO cloud progress yet and whose version matches.
     *
     * @param list<mixed> $items [{slug, page, v}]
     * @return int rows imported
     */
    public static function importLocalProgress(int $userId, array $items): int
    {
        $repo = LibraryRepositoryFactory::make();
        $imported = 0;
        foreach (array_slice($items, 0, 50) as $it) {
            if (!is_array($it) || !is_string($it['slug'] ?? null) || !preg_match('/^[a-z0-9-]{1,191}$/', $it['slug'])) {
                continue;
            }
            $book = $repo->findBookBySlug($it['slug']);
            if ($book === null || !BookAccessPolicy::canView($book) || !$book['current_file_id']) {
                continue;
            }
            if (Database::scalar('SELECT 1 FROM reading_progress WHERE user_id = :u AND book_id = :b', ['u' => $userId, 'b' => $book['id']])) {
                continue;
            }
            $file = Database::first("SELECT id, version_number, page_count FROM book_files WHERE id = :f AND render_status = 'ready'", ['f' => $book['current_file_id']]);
            if (!$file || (int) ($it['v'] ?? 0) !== (int) $file['version_number']) {
                continue;
            }
            try {
                \App\Services\ReadingProgressService::save($userId, $book, ['id' => (int) $file['id'], 'version' => (int) $file['version_number'], 'pages' => (int) $file['page_count']], (int) ($it['page'] ?? 0), (int) $it['v']);
                $imported++;
            } catch (HttpException) {
                // invalid page for this version: skip silently
            }
        }
        return $imported;
    }
}
