<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use App\Services\SearchIndexer;

/**
 * Deterministic test catalogue (portable SQL; used on SQLite and MySQL).
 * "Now" for visibility is fixed at 2026-11-05 12:00:00.
 *
 * Visible:    b1 (featured), b2, b3 (diacritics in title, child category), b4 (members), b9 (% and _ in title)
 * Invisible:  b5 draft, b6 archived, b7 scheduled in the future, b8 soft-deleted
 */
final class LibraryFixtures
{
    public const NOW = '2026-11-05 12:00:00';

    /** @return array<string,int> symbolic name => id */
    public static function load(): array
    {
        $ids = [];
        $q = static function (string $sql, array $p = []) { Database::query($sql, $p); return Database::lastInsertId(); };
        $t = '2026-11-01 00:00:00';

        foreach ([['admin', 'admin', 'active'], ['member', 'user', 'active'], ['suspended', 'user', 'suspended']] as [$n, $role, $st]) {
            $ids["u_$n"] = $q('INSERT INTO users (google_sub, email, name, role, status, created_at, updated_at) VALUES (:s, :e, :n, :r, :st, :c, :u)',
                ['s' => "sub-$n", 'e' => "$n@example.invalid", 'n' => ucfirst($n) . ' User', 'r' => $role, 'st' => $st, 'c' => $t, 'u' => $t]);
        }

        $cat = static fn (string $slug, string $ar, string $en, int $ord, string $status = 'active', ?int $parent = null) => $q(
            'INSERT INTO categories (parent_id, name_ar, name_en, slug, sort_order, status, created_at, updated_at) VALUES (:p, :ar, :en, :s, :o, :st, :c, :u)',
            ['p' => $parent, 'ar' => $ar, 'en' => $en, 's' => $slug, 'o' => $ord, 'st' => $status, 'c' => $t, 'u' => $t]);
        $ids['c_lit'] = $cat('literature', 'الأدب', 'Literature', 1);
        $ids['c_hist'] = $cat('history', 'التاريخ', 'History', 2);
        $ids['c_sci'] = $cat('science', 'العلوم', 'Science', 3);
        $ids['c_astro'] = $cat('astronomy', 'الفلك', 'Astronomy', 4, 'active', $ids['c_sci']);
        $ids['c_hidden'] = $cat('hidden-cat', 'تصنيف معطل', 'Hidden', 5, 'inactive');

        $auth = static fn (string $slug, string $name) => $q('INSERT INTO authors (name, slug, created_at, updated_at) VALUES (:n, :s, :c, :u)', ['n' => $name, 's' => $slug, 'c' => $t, 'u' => $t]);
        $ids['a_salma'] = $auth('salma-al-haddad', 'سلمى الحداد');
        $ids['a_nora'] = $auth('nora-castell', 'Nora Castell');
        $ids['a_karim'] = $auth('karim-al-tazi', 'كريم التازي');
        $ids['a_ghost'] = $auth('ghost-writer', 'كاتب الظل');

        $book = static function (string $key, array $b) use ($q, &$ids, $t): void {
            $ids[$key] = $q(
                'INSERT INTO books (title, slug, description, language, visibility, status, is_featured, reads_count, rating_avg, rating_count,
                                    rights_status, rights_source, published_at, deleted_at, created_at, updated_at)
                 VALUES (:title, :slug, :d, :lang, :vis, :st, :feat, :reads, :ravg, :rcount, :rs, :src, :pub, :del, :c, :u)',
                ['title' => $b['title'], 'slug' => $b['slug'], 'd' => $b['d'] ?? null, 'lang' => $b['lang'], 'vis' => $b['vis'] ?? 'public',
                 'st' => $b['st'] ?? 'published', 'feat' => $b['feat'] ?? 0, 'reads' => $b['reads'] ?? 0, 'ravg' => $b['ravg'] ?? 0,
                 'rcount' => $b['rcount'] ?? 0, 'rs' => 'licensed', 'src' => 'test fixture', 'pub' => $b['pub'] ?? null, 'del' => $b['del'] ?? null, 'c' => $t, 'u' => $t]
            );
        };
        $book('b1', ['title' => 'حدائق النهر الهادئ', 'slug' => 'gardens-river', 'lang' => 'ar', 'feat' => 1, 'reads' => 100, 'ravg' => 4.6, 'rcount' => 10, 'pub' => '2026-10-28 09:00:00', 'd' => 'رواية عن عائلة وبيت قديم.']);
        $book('b2', ['title' => "The Cartographer's Notebook", 'slug' => 'cartographer-notebook', 'lang' => 'en', 'reads' => 300, 'ravg' => 4.4, 'rcount' => 5, 'pub' => '2026-10-21 09:00:00', 'd' => 'A mapmaker inherits an atlas.']);
        $book('b3', ['title' => 'النُّجُومُ للمبتدئين', 'slug' => 'stars-beginners', 'lang' => 'ar', 'reads' => 50, 'ravg' => 4.8, 'rcount' => 7, 'pub' => '2026-10-18 09:00:00']);
        $book('b4', ['title' => 'مدرسة الإسكندرية', 'slug' => 'alexandria-school', 'lang' => 'ar', 'vis' => 'members', 'reads' => 10, 'pub' => '2026-09-01 09:00:00']);
        $book('b5', ['title' => 'مسودة سرية', 'slug' => 'secret-draft', 'lang' => 'ar', 'st' => 'draft', 'reads' => 999]);
        $book('b6', ['title' => 'كتاب مؤرشف', 'slug' => 'archived-book', 'lang' => 'ar', 'st' => 'archived', 'reads' => 999, 'pub' => '2026-01-01 09:00:00']);
        $book('b7', ['title' => 'كتاب مجدول', 'slug' => 'scheduled-book', 'lang' => 'ar', 'reads' => 999, 'pub' => '2027-01-01 09:00:00']);
        $book('b8', ['title' => 'كتاب محذوف', 'slug' => 'deleted-book', 'lang' => 'ar', 'reads' => 999, 'pub' => '2026-01-01 09:00:00', 'del' => '2026-02-01 00:00:00']);
        $book('b9', ['title' => '100% Pure_Data', 'slug' => 'pure-data', 'lang' => 'en', 'reads' => 5, 'pub' => '2026-10-01 09:00:00']);

        $link = static function (string $b, array $authors, array $cats) use (&$ids): void {
            foreach ($authors as $i => $a) {
                Database::query('INSERT INTO book_authors (book_id, author_id, author_order) VALUES (:b, :a, :o)', ['b' => $ids[$b], 'a' => $ids[$a], 'o' => $i + 1]);
            }
            foreach ($cats as $c) {
                Database::query('INSERT INTO book_categories (book_id, category_id) VALUES (:b, :c)', ['b' => $ids[$b], 'c' => $ids[$c]]);
            }
        };
        $link('b1', ['a_salma'], ['c_lit', 'c_hidden']);
        $link('b2', ['a_nora'], ['c_hist', 'c_lit']);
        $link('b3', ['a_karim'], ['c_astro']);
        $link('b4', ['a_salma'], ['c_hist']);
        $link('b5', ['a_ghost', 'a_salma'], ['c_lit']);
        $link('b6', ['a_salma'], ['c_lit']);
        $link('b7', ['a_salma'], ['c_lit']);
        $link('b8', ['a_salma'], ['c_lit']);
        $link('b9', ['a_nora'], ['c_sci']);

        $review = static fn (string $u, string $b, int $r, string $c, string $st) => Database::query(
            'INSERT INTO reviews (user_id, book_id, rating, comment, status, created_at, updated_at) VALUES (:u, :b, :r, :c, :s, :t, :t2)',
            ['u' => $ids[$u], 'b' => $ids[$b], 'r' => $r, 'c' => $c, 's' => $st, 't' => $t, 't2' => $t]);
        $review('u_member', 'b1', 5, 'Visible review', 'published');
        $review('u_suspended', 'b1', 1, 'Suspended user review', 'published');
        $review('u_admin', 'b1', 2, 'Hidden review', 'hidden');

        SearchIndexer::reindexAll();
        return $ids;
    }

    /** Attach a validated, rendered file version so publish rules can pass. */
    public static function attachReadyFile(int $bookId, int $pages = 12, string $validation = 'valid', string $render = 'ready'): int
    {
        Database::query(
            "INSERT INTO book_files (book_id, storage_path, original_name, mime_type, file_size, checksum_sha256, version_number,
                                     validation_status, render_status, page_count, created_at)
             VALUES (:b, 'test/none', 'x.pdf', 'application/pdf', 1, :sum, :v, :val, :ren, :pc, :c)",
            ['b' => $bookId, 'sum' => str_repeat('a', 64), 'v' => 1 + (int) Database::scalar('SELECT COUNT(*) FROM book_files WHERE book_id = :b', ['b' => $bookId]),
             'val' => $validation, 'ren' => $render, 'pc' => $pages, 'c' => self::NOW]
        );
        $fid = Database::lastInsertId();
        Database::query('UPDATE books SET current_file_id = :f WHERE id = :b', ['f' => $fid, 'b' => $bookId]);
        return $fid;
    }
}
