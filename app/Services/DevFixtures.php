<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Demo\DemoData;
use RuntimeException;

/**
 * Loads the FICTIONAL sample catalogue (app/Demo) into a DEVELOPMENT database
 * so the real MySQL-backed pages can be exercised before real books exist.
 *
 * Safety: refuses in production; refuses if the books table is not empty;
 * every row is marked in rights_source as a fictional development fixture,
 * and a fake rendered "file version" is created only so the publish rules
 * (valid + ready file) are satisfied — it has no PDF and no page images.
 */
final class DevFixtures
{
    public const MARKER = 'DEV FIXTURE — fictional sample data, not a real book';

    public static function seed(): array
    {
        if (config('app.env') === 'production') {
            throw new RuntimeException('Refusing to seed fictional data in production.');
        }
        if ((int) Database::scalar('SELECT COUNT(*) FROM books') > 0) {
            throw new RuntimeException('The books table is not empty; fixtures are only loaded into an empty development database.');
        }

        return Database::transaction(static function (): array {
            $now = gmdate('Y-m-d H:i:s');
            Database::query(
                "INSERT INTO users (google_sub, email, name, role, status, created_at, updated_at)
                 VALUES ('dev-fixture-admin', 'dev-admin@example.invalid', 'Dev Fixture Admin', 'admin', 'active', :c, :u)",
                ['c' => $now, 'u' => $now]
            );
            $adminId = Database::lastInsertId();

            $catIds = [];
            foreach (DemoData::categories() as $i => $c) {
                Database::query(
                    "INSERT INTO categories (name_ar, name_en, slug, description_ar, description_en, sort_order, status, created_at, updated_at)
                     VALUES (:ar, :en, :slug, :dar, :den, :ord, 'active', :c, :u)",
                    ['ar' => $c['name_ar'], 'en' => $c['name_en'], 'slug' => $c['slug'], 'dar' => $c['description_ar'], 'den' => $c['description_en'], 'ord' => $i, 'c' => $now, 'u' => $now]
                );
                $catIds[$c['id']] = Database::lastInsertId();
            }
            $authorIds = [];
            foreach (DemoData::authors() as $a) {
                Database::query('INSERT INTO authors (name, slug, created_at, updated_at) VALUES (:n, :s, :c, :u)', ['n' => $a['name'], 's' => $a['slug'], 'c' => $now, 'u' => $now]);
                $authorIds[$a['id']] = Database::lastInsertId();
            }

            foreach (DemoData::books() as $b) {
                Database::query(
                    "INSERT INTO books (title, slug, description, language, publisher, publication_year, page_count, visibility, status,
                                        is_featured, rights_status, license_name, rights_holder, rights_source, rights_verified_at,
                                        reads_count, rating_avg, rating_count, published_at, created_by, created_at, updated_at)
                     VALUES (:title, :slug, :descr, :lang, :pub, :year, :pages, 'public', 'published', :feat, :rights, :lic,
                             'Fictional', :src, :rv, :reads, :ravg, :rcount, :published, :by, :c, :u)",
                    [
                        'title' => $b['title'], 'slug' => $b['slug'], 'descr' => $b['description'], 'lang' => $b['language'],
                        'pub' => $b['publisher'], 'year' => $b['publication_year'], 'pages' => $b['page_count'],
                        'feat' => $b['is_featured'] ? 1 : 0, 'rights' => $b['rights_status'], 'lic' => $b['license_name'],
                        'src' => self::MARKER, 'rv' => $now, 'reads' => $b['reads_count'], 'ravg' => $b['rating_avg'],
                        'rcount' => $b['rating_count'], 'published' => $b['published_at'] . ' 09:00:00', 'by' => $adminId, 'c' => $now, 'u' => $now,
                    ]
                );
                $bookId = Database::lastInsertId();
                Database::query(
                    "INSERT INTO book_files (book_id, storage_path, original_name, mime_type, file_size, checksum_sha256, version_number,
                                             validation_status, render_status, render_engine, page_count, uploaded_by, created_at, rendered_at)
                     VALUES (:b, 'dev-fixture/none', 'fixture.pdf', 'application/pdf', 0, :sum, 1, 'valid', 'ready', 'dev-fixture', :pages, :by, :c, :r)",
                    ['b' => $bookId, 'sum' => str_repeat('0', 64), 'pages' => $b['page_count'], 'by' => $adminId, 'c' => $now, 'r' => $now]
                );
                Database::query('UPDATE books SET current_file_id = :f WHERE id = :b', ['f' => Database::lastInsertId(), 'b' => $bookId]);
                foreach ($b['author_ids'] as $i => $aid) {
                    Database::query('INSERT INTO book_authors (book_id, author_id, author_order) VALUES (:b, :a, :o)', ['b' => $bookId, 'a' => $authorIds[$aid], 'o' => $i + 1]);
                }
                foreach ($b['category_ids'] as $cid) {
                    Database::query('INSERT INTO book_categories (book_id, category_id) VALUES (:b, :c)', ['b' => $bookId, 'c' => $catIds[$cid]]);
                }
                SearchIndexer::reindexBook($bookId);
            }
            return ['books' => count(DemoData::books()), 'categories' => count($catIds), 'authors' => count($authorIds), 'admin_user_id' => $adminId];
        });
    }
}
