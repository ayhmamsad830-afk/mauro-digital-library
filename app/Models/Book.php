<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Repositories\MySqlLibraryRepository;
use App\Support\Paginator;

/**
 * Admin-side book persistence (all statuses). The public catalogue never uses
 * this class; it reads through MySqlLibraryRepository, which enforces visibility.
 */
final class Book
{
    public const STATUSES = ['draft', 'published', 'archived'];

    private const WRITABLE = ['title', 'title_en', 'slug', 'description', 'description_en', 'language', 'publisher', 'edition', 'isbn', 'publication_year', 'visibility',
        'is_featured', 'rights_status', 'license_name', 'rights_holder', 'rights_source'];

    public static function find(int $id): ?array
    {
        $row = Database::first('SELECT * FROM books WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
        if (!$row) {
            return null;
        }
        $row['author_ids'] = array_map('intval', Database::query('SELECT author_id FROM book_authors WHERE book_id = :id ORDER BY author_order', ['id' => $id])->fetchAll(\PDO::FETCH_COLUMN));
        $row['category_ids'] = array_map('intval', Database::query('SELECT category_id FROM book_categories WHERE book_id = :id', ['id' => $id])->fetchAll(\PDO::FETCH_COLUMN));
        $row['current_file'] = $row['current_file_id']
            ? Database::first('SELECT id, version_number, validation_status, render_status, page_count, original_name, file_size, created_at FROM book_files WHERE id = :f', ['f' => (int) $row['current_file_id']])
            : null;
        return $row;
    }

    /** Admin list with search + status filter, paginated in SQL. */
    public static function paginate(string $q, ?string $status, int $page, int $perPage = 20): Paginator
    {
        $where = ['b.deleted_at IS NULL'];
        $params = [];
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $where[] = 'b.status = :status';
            $params['status'] = $status;
        }
        foreach (MySqlLibraryRepository::tokens($q) as $i => $t) {
            $where[] = "(b.search_text LIKE :q$i ESCAPE '!' OR b.slug LIKE :s$i ESCAPE '!')";
            $params["q$i"] = '%' . MySqlLibraryRepository::escapeLike($t) . '%';
            $params["s$i"] = $params["q$i"];
        }
        $w = implode(' AND ', $where);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM books b WHERE $w", $params);
        $last = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $last));
        $rows = Database::all(
            "SELECT b.id, b.title, b.slug, b.language, b.status, b.visibility, b.page_count, b.published_at, b.updated_at,
                    b.rights_status, b.current_file_id,
                    (SELECT COUNT(*) FROM book_authors ba WHERE ba.book_id = b.id) AS authors_count
               FROM books b WHERE $w ORDER BY b.updated_at DESC, b.id DESC LIMIT :lim OFFSET :off",
            $params + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );
        return new Paginator($rows, $total, $page, $perPage);
    }

    /** @return array<string,int> counts per status (non-deleted) */
    public static function statusCounts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (Database::all('SELECT status, COUNT(*) AS n FROM books WHERE deleted_at IS NULL GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $counts;
    }

    public static function insert(array $d, int $createdBy): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $cols = self::WRITABLE;
        $sql = 'INSERT INTO books (' . implode(', ', $cols) . ', status, created_by, created_at, updated_at) VALUES (:'
            . implode(', :', $cols) . ", 'draft', :created_by, :c, :u)";
        Database::query($sql, self::params($d) + ['created_by' => $createdBy, 'c' => $now, 'u' => $now]);
        return Database::lastInsertId();
    }

    public static function update(int $id, array $d): void
    {
        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", self::WRITABLE));
        Database::query("UPDATE books SET $set, updated_at = :u WHERE id = :id", self::params($d) + ['u' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
    }

    /** @param list<int> $authorIds ordered */
    public static function syncAuthors(int $bookId, array $authorIds): void
    {
        Database::query('DELETE FROM book_authors WHERE book_id = :id', ['id' => $bookId]);
        foreach (array_values($authorIds) as $i => $aid) {
            Database::query('INSERT INTO book_authors (book_id, author_id, author_order) VALUES (:b, :a, :o)', ['b' => $bookId, 'a' => $aid, 'o' => $i + 1]);
        }
    }

    /** @param list<int> $categoryIds */
    public static function syncCategories(int $bookId, array $categoryIds): void
    {
        Database::query('DELETE FROM book_categories WHERE book_id = :id', ['id' => $bookId]);
        foreach ($categoryIds as $cid) {
            Database::query('INSERT INTO book_categories (book_id, category_id) VALUES (:b, :c)', ['b' => $bookId, 'c' => $cid]);
        }
    }

    public static function setStatus(int $id, string $status, ?string $publishedAt = null, bool $markRightsVerified = false): void
    {
        $sql = 'UPDATE books SET status = :status, updated_at = :u';
        $params = ['status' => $status, 'u' => gmdate('Y-m-d H:i:s'), 'id' => $id];
        if ($publishedAt !== null) {
            $sql .= ', published_at = COALESCE(published_at, :pub)';
            $params['pub'] = $publishedAt;
        }
        if ($markRightsVerified) {
            $sql .= ', rights_verified_at = COALESCE(rights_verified_at, :rv)';
            $params['rv'] = gmdate('Y-m-d H:i:s');
        }
        Database::query($sql . ' WHERE id = :id', $params);
    }

    private static function params(array $d): array
    {
        $p = [];
        foreach (self::WRITABLE as $c) {
            $v = $d[$c] ?? null;
            $p[$c] = $c === 'is_featured' ? (int) (bool) $v : $v;
        }
        return $p;
    }
}
