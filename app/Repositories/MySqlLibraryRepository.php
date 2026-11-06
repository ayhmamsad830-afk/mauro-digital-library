<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\LibraryRepository;
use App\Core\Database;
use App\Services\ArabicNormalizer;
use App\Services\Media\ImageUploadService;
use App\Support\BookQuery;
use App\Support\Paginator;

/**
 * Public, read-only catalogue backed by MySQL (InnoDB, utf8mb4).
 *
 * Security
 *  - Every value reaches SQL through a bound parameter. The only SQL fragments
 *    built dynamically are (a) ORDER BY clauses picked from a fixed whitelist
 *    and (b) generated placeholder names (":c0", ":c1"…), never user text.
 *  - Placeholder names are unique per statement: MySQL native prepares
 *    (ATTR_EMULATE_PREPARES=false) reject a repeated named parameter.
 *
 * Visibility (spec §5.1 FR-002, TC-006): a book is visible only when
 *   status = 'published' AND deleted_at IS NULL AND published_at <= now
 *   AND (visibility = 'public' OR the viewer is a signed-in member).
 * Drafts, archived, soft-deleted and future-scheduled books are invisible to
 * everyone; members-only books are invisible to guests. The rule lives in one
 * place (visible()) and is applied to every query: lists, details, related,
 * category/author counts, languages and featured.
 *
 * Search (spec §6.4): books.search_text holds title + authors + categories +
 * publisher + description, folded by ArabicNormalizer (diacritics, alef/ya/ta
 * marbuta variants, tatweel, Arabic-Indic digits, lowercase). The query is
 * folded the same way and split into up to 5 tokens; every token must match
 * (AND) as a substring via LIKE with an explicit escape character.
 *
 * The SQL is deliberately portable (no MySQL-only functions) so the same class
 * is exercised by the SQLite logic tests as well as the MySQL integration tests.
 */
final class MySqlLibraryRepository implements LibraryRepository
{
    private const SORTS = [
        'newest' => 'b.published_at DESC, b.id DESC',
        'popular' => 'b.reads_count DESC, b.published_at DESC, b.id DESC',
        'rating' => 'b.rating_avg DESC, b.rating_count DESC, b.id DESC',
        'title' => 'b.title ASC, b.id ASC',
    ];

    private const BOOK_COLUMNS = 'b.id, b.slug, b.title, b.title_en, b.description, b.description_en, b.cover_path, b.language, b.publisher, b.edition, b.isbn,
        b.publication_year, b.page_count, b.visibility, b.is_featured, b.current_file_id, b.rights_status,
        b.license_name, b.reads_count, b.rating_avg, b.rating_count, b.published_at';

    private const MAX_SEARCH_TOKENS = 5;

    /** @var (callable(): string)|null clock override for tests */
    private $clock;

    /** @var callable(): bool whether the current viewer is a signed-in member */
    private $isMember;

    /**
     * @param (callable(): string)|null $clock     "now" provider (tests)
     * @param (callable(): bool)|null   $isMember  viewer resolver; defaults to the
     *        authenticated session (evaluated per query, never cached)
     */
    public function __construct(?callable $clock = null, ?callable $isMember = null)
    {
        $this->clock = $clock;
        $this->isMember = $isMember ?? static fn (): bool => \App\Services\BookAccessPolicy::viewerIsMember();
    }

    public function isDemo(): bool
    {
        return false;
    }

    private function now(): string
    {
        return $this->clock ? ($this->clock)() : gmdate('Y-m-d H:i:s');
    }

    /** Shared visibility predicate + its parameters. */
    private function visible(string $alias = 'b'): array
    {
        $sql = "$alias.status = 'published' AND $alias.deleted_at IS NULL AND $alias.published_at IS NOT NULL AND $alias.published_at <= :vis_now";
        if (!($this->isMember)()) {
            // Guests never see members-only books — not even their existence or counts.
            $sql .= " AND $alias.visibility = 'public'";
        }
        return [$sql, ['vis_now' => $this->now()]];
    }

    /**
     * Public sitemap entries. Always evaluated as a GUEST: members-only books
     * never appear, whoever requests the sitemap.
     *
     * @return array{books: list<array{slug:string,updated_at:string}>, categories: list<string>}
     */
    public function sitemapEntries(int $limit = 5000): array
    {
        $guest = new self($this->clock, static fn (): bool => false);
        [$vis, $params] = $guest->visible();
        $books = Database::all("SELECT b.slug, b.updated_at FROM books b WHERE $vis ORDER BY b.published_at DESC, b.id DESC LIMIT :lim", $params + ['lim' => $limit]);
        $categories = array_values(array_map(static fn (array $c): string => $c['slug'], array_filter($guest->categories(), static fn (array $c): bool => $c['books_count'] > 0)));
        return ['books' => $books, 'categories' => $categories];
    }

    // ------------------------------------------------------------------ home

    public function featuredBook(): ?array
    {
        [$vis, $params] = $this->visible();

        // 1) Book chosen by the admin in settings (home.featured_book_id).
        $chosen = Database::scalar("SELECT setting_value FROM settings WHERE setting_key = 'home.featured_book_id'");
        if (is_string($chosen) && ctype_digit($chosen)) {
            $row = Database::first('SELECT ' . self::BOOK_COLUMNS . " FROM books b WHERE b.id = :id AND $vis", $params + ['id' => (int) $chosen]);
            if ($row) {
                return $this->hydrate([$row])[0];
            }
        }
        // 2) Most recent book flagged as featured.
        $row = Database::first('SELECT ' . self::BOOK_COLUMNS . " FROM books b WHERE b.is_featured = 1 AND $vis ORDER BY b.published_at DESC, b.id DESC LIMIT 1", $params);
        return $row ? $this->hydrate([$row])[0] : null;
    }

    public function latestBooks(int $limit): array
    {
        return $this->listBooks('newest', $limit);
    }

    public function popularBooks(int $limit): array
    {
        return $this->listBooks('popular', $limit);
    }

    private function listBooks(string $sort, int $limit): array
    {
        [$vis, $params] = $this->visible();
        $rows = Database::all(
            'SELECT ' . self::BOOK_COLUMNS . " FROM books b WHERE $vis ORDER BY " . self::SORTS[$sort] . ' LIMIT :lim',
            $params + ['lim' => max(1, min($limit, 100))]
        );
        return $this->hydrate($rows);
    }

    // ------------------------------------------------------------- taxonomies

    public function categories(): array
    {
        [$vis, $params] = $this->visible();
        $rows = Database::all(
            "SELECT c.id, c.parent_id, c.slug, c.name_ar, c.name_en, c.description_ar, c.description_en, c.sort_order,
                    (SELECT COUNT(*) FROM book_categories bc JOIN books b ON b.id = bc.book_id
                      WHERE bc.category_id = c.id AND $vis) AS books_count
               FROM categories c
              WHERE c.status = 'active'
              ORDER BY c.sort_order ASC, c.name_ar ASC, c.id ASC",
            $params
        );
        return array_map([$this, 'mapCategory'], $rows);
    }

    public function findCategoryBySlug(string $slug): ?array
    {
        [$vis, $params] = $this->visible();
        $row = Database::first(
            "SELECT c.id, c.parent_id, c.slug, c.name_ar, c.name_en, c.description_ar, c.description_en, c.sort_order,
                    (SELECT COUNT(*) FROM book_categories bc JOIN books b ON b.id = bc.book_id
                      WHERE bc.category_id = c.id AND $vis) AS books_count
               FROM categories c
              WHERE c.slug = :slug AND c.status = 'active'",
            $params + ['slug' => $slug]
        );
        return $row ? $this->mapCategory($row) : null;
    }

    public function authors(): array
    {
        [$vis, $params] = $this->visible();
        $rows = Database::all(
            "SELECT a.id, a.slug, a.name, a.photo_path, COUNT(b.id) AS books_count
               FROM authors a
               JOIN book_authors ba ON ba.author_id = a.id
               JOIN books b ON b.id = ba.book_id AND $vis
              GROUP BY a.id, a.slug, a.name, a.photo_path
              ORDER BY a.name ASC, a.id ASC",
            $params
        );
        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'books_count' => (int) $r['books_count'],
            'photo_path' => $r['photo_path'], 'photo_url' => $r['photo_path'] ? route('media.author', ['id' => (int) $r['id']]) . '?v=' . ImageUploadService::version((string) $r['photo_path']) : null], $rows);
    }

    public function languages(): array
    {
        [$vis, $params] = $this->visible();
        return array_map('strval', Database::query("SELECT DISTINCT b.language FROM books b WHERE $vis ORDER BY b.language", $params)->fetchAll(\PDO::FETCH_COLUMN));
    }

    // ----------------------------------------------------------------- search

    public function searchBooks(BookQuery $query): Paginator
    {
        [$where, $params] = $this->visible();
        $conditions = [$where];

        // Free-text: every normalised token must appear in search_text.
        foreach (self::tokens($query->q) as $i => $token) {
            $conditions[] = "b.search_text LIKE :q$i ESCAPE '!'";
            $params["q$i"] = '%' . self::escapeLike($token) . '%';
        }

        if ($query->category !== null) {
            // Category filter includes its direct sub-categories (parent_id).
            $conditions[] = "EXISTS (SELECT 1 FROM book_categories fbc JOIN categories fc ON fc.id = fbc.category_id
                                     LEFT JOIN categories fp ON fp.id = fc.parent_id
                                     WHERE fbc.book_id = b.id AND fc.status = 'active'
                                       AND (fc.slug = :f_cat OR fp.slug = :f_cat_parent))";
            $params['f_cat'] = $query->category;
            $params['f_cat_parent'] = $query->category;
        }
        if ($query->author !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM book_authors fba JOIN authors fa ON fa.id = fba.author_id
                                     WHERE fba.book_id = b.id AND fa.slug = :f_author)';
            $params['f_author'] = $query->author;
        }
        if ($query->language !== null) {
            $conditions[] = 'b.language = :f_lang';
            $params['f_lang'] = $query->language;
        }

        $whereSql = implode(' AND ', $conditions);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM books b WHERE $whereSql", $params);

        $lastPage = max(1, (int) ceil($total / $query->perPage));
        $page = min($query->page, $lastPage);
        if ($total === 0) {
            return new Paginator([], 0, 1, $query->perPage);
        }

        $order = self::SORTS[$query->sort] ?? self::SORTS['newest'];
        $rows = Database::all(
            'SELECT ' . self::BOOK_COLUMNS . " FROM books b WHERE $whereSql ORDER BY $order LIMIT :lim OFFSET :off",
            $params + ['lim' => $query->perPage, 'off' => ($page - 1) * $query->perPage]
        );
        return new Paginator($this->hydrate($rows), $total, $page, $query->perPage);
    }

    // ---------------------------------------------------------------- details

    public function findBookBySlug(string $slug): ?array
    {
        [$vis, $params] = $this->visible();
        $row = Database::first('SELECT ' . self::BOOK_COLUMNS . " FROM books b WHERE b.slug = :slug AND $vis", $params + ['slug' => $slug]);
        return $row ? $this->hydrate([$row])[0] : null;
    }

    public function findBookById(int $id): ?array
    {
        [$vis, $params] = $this->visible();
        $row = Database::first('SELECT ' . self::BOOK_COLUMNS . " FROM books b WHERE b.id = :id AND $vis", $params + ['id' => $id]);
        return $row ? $this->hydrate([$row])[0] : null;
    }

    public function relatedBooks(array $book, int $limit): array
    {
        [$vis, $params] = $this->visible();
        $rows = Database::all(
            'SELECT ' . self::BOOK_COLUMNS . " FROM books b
              WHERE $vis AND b.id <> :self_id AND (
                    EXISTS (SELECT 1 FROM book_categories x JOIN book_categories y ON y.category_id = x.category_id
                             WHERE x.book_id = b.id AND y.book_id = :cat_ref)
                 OR EXISTS (SELECT 1 FROM book_authors x JOIN book_authors y ON y.author_id = x.author_id
                             WHERE x.book_id = b.id AND y.book_id = :auth_ref))
              ORDER BY b.reads_count DESC, b.id DESC LIMIT :lim",
            $params + ['self_id' => (int) $book['id'], 'cat_ref' => (int) $book['id'], 'auth_ref' => (int) $book['id'], 'lim' => max(1, min($limit, 24))]
        );
        return $this->hydrate($rows);
    }

    public function bookReviews(int $bookId, int $limit): array
    {
        $rows = Database::all(
            "SELECT r.rating, r.comment, r.created_at, u.name AS user_name
               FROM reviews r JOIN users u ON u.id = r.user_id
              WHERE r.book_id = :book AND r.status = 'published' AND u.status = 'active'
              ORDER BY r.created_at DESC, r.id DESC LIMIT :lim",
            ['book' => $bookId, 'lim' => max(1, min($limit, 50))]
        );
        return array_map(static fn (array $r) => [
            'rating' => (int) $r['rating'],
            'comment' => $r['comment'],
            'created_at' => $r['created_at'],
            'user_name' => $r['user_name'] !== '' ? $r['user_name'] : '—',
        ], $rows);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Attach authors and categories with two batched queries (no N+1) and
     * normalise types to the shared view shape.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        [$in, $inParams] = self::inList($ids, 'id');

        $authors = [];
        foreach (Database::all(
            "SELECT ba.book_id, a.id, a.slug, a.name FROM book_authors ba JOIN authors a ON a.id = ba.author_id
              WHERE ba.book_id IN ($in) ORDER BY ba.author_order ASC, a.name ASC",
            $inParams
        ) as $a) {
            $authors[(int) $a['book_id']][] = ['id' => (int) $a['id'], 'slug' => $a['slug'], 'name' => $a['name']];
        }

        $categories = [];
        foreach (Database::all(
            "SELECT bc.book_id, c.id, c.slug, c.name_ar, c.name_en FROM book_categories bc JOIN categories c ON c.id = bc.category_id
              WHERE bc.book_id IN ($in) AND c.status = 'active' ORDER BY c.sort_order ASC, c.id ASC",
            $inParams
        ) as $c) {
            $categories[(int) $c['book_id']][] = [
                'id' => (int) $c['id'], 'slug' => $c['slug'], 'name_ar' => $c['name_ar'], 'name_en' => $c['name_en'],
                'tone' => ((int) $c['id'] % 8) + 1,
            ];
        }

        return array_map(static function (array $r) use ($authors, $categories): array {
            $id = (int) $r['id'];
            return [
                'id' => $id,
                'slug' => $r['slug'],
                'title' => $r['title'],
                'title_en' => $r['title_en'] ?? null,
                'description' => (string) ($r['description'] ?? ''),
                'description_en' => (string) ($r['description_en'] ?? ''),
                'language' => $r['language'],
                'publisher' => $r['publisher'],
                'edition' => $r['edition'] ?? null,
                'isbn' => $r['isbn'] ?? null,
                'publication_year' => $r['publication_year'] !== null ? (int) $r['publication_year'] : null,
                'page_count' => $r['page_count'] !== null ? (int) $r['page_count'] : null,
                'visibility' => $r['visibility'],
                'is_featured' => (bool) $r['is_featured'],
                'has_file' => $r['current_file_id'] !== null,
                'current_file_id' => $r['current_file_id'] !== null ? (int) $r['current_file_id'] : null,
                'rights_status' => $r['rights_status'],
                'license_name' => $r['license_name'],
                'reads_count' => (int) $r['reads_count'],
                'rating_avg' => (float) $r['rating_avg'],
                'rating_count' => (int) $r['rating_count'],
                'published_at' => $r['published_at'],
                // Covers are served by MediaController (visibility re-checked per request).
                'cover_path' => $r['cover_path'] ?? null,
                'cover_url' => !empty($r['cover_path']) ? route('media.cover', ['id' => $id]) . '?v=' . ImageUploadService::version((string) $r['cover_path']) : null,
                'cover_tone' => ($id % 8) + 1,
                'authors' => $authors[$id] ?? [],
                'categories' => $categories[$id] ?? [],
            ];
        }, $rows);
    }

    private function mapCategory(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
            'slug' => $r['slug'],
            'name_ar' => $r['name_ar'],
            'name_en' => $r['name_en'],
            'description_ar' => $r['description_ar'],
            'description_en' => $r['description_en'],
            'tone' => ((int) $r['id'] % 8) + 1,
            'books_count' => (int) $r['books_count'],
        ];
    }

    /**
     * Normalised, de-duplicated search tokens (max 5, each ≥ 1 char).
     * @return list<string>
     */
    public static function tokens(string $q): array
    {
        $norm = ArabicNormalizer::normalize($q);
        if ($norm === '') {
            return [];
        }
        $parts = array_values(array_unique(array_filter(preg_split('/\s+/u', $norm) ?: [], static fn ($t) => $t !== '')));
        return array_slice($parts, 0, self::MAX_SEARCH_TOKENS);
    }

    /** Escape LIKE wildcards with '!' (an escape char that means the same in MySQL and SQLite). */
    public static function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /**
     * Build ":id0, :id1, …" placeholders for an IN() list.
     * @param list<int> $values
     * @return array{0:string, 1:array<string,int>}
     */
    public static function inList(array $values, string $prefix): array
    {
        $names = [];
        $params = [];
        foreach (array_values($values) as $i => $v) {
            $names[] = ":{$prefix}{$i}";
            $params["{$prefix}{$i}"] = (int) $v;
        }
        return [implode(', ', $names), $params];
    }
}
