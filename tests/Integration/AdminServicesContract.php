<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Models\Book;
use App\Services\Admin\AuthorService;
use App\Services\Admin\BookService;
use App\Services\Admin\CategoryService;
use App\Support\BookQuery;
use DomainException;
use Tests\Support\LibraryFixtures;

/**
 * Admin write-side behaviour (books / categories / authors) against a real
 * schema. Requires $this->ids from LibraryFixtures and repo() from
 * LibraryRepositoryContract.
 */
trait AdminServicesContract
{
    private function bookInput(array $over = []): array
    {
        return $over + [
            'title' => 'كِتَابُ الاختبار',
            'slug' => '',
            'description' => 'وصف <b>غير</b> موثوق',
            'language' => 'ar',
            'publisher' => 'دار الاختبار',
            'publication_year' => '2024',
            'visibility' => 'public',
            'rights_status' => 'licensed',
            'license_name' => 'Test licence',
            'rights_holder' => 'Holder',
            'rights_source' => 'Contract #42',
            'author_ids' => [(string) $this->ids['a_karim']],
            'category_ids' => [(string) $this->ids['c_hist']],
        ];
    }

    private function logCount(string $action): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM admin_activity_logs WHERE action = :a', ['a' => $action]);
    }

    public function testCreateBookIsDraftWithTransliteratedSlugIndexAndLog(): void
    {
        [$id, $v] = BookService::create($this->bookInput(), $this->ids['u_admin'], '127.0.0.1');
        $this->assertFalse($v->fails(), json_encode($v->errors, JSON_UNESCAPED_UNICODE));
        $book = Book::find($id);
        $this->assertSame('draft', $book['status']);
        $this->assertTrue((bool) preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $book['slug']), 'ASCII slug: ' . $book['slug']);
        $this->assertSame([$this->ids['a_karim']], $book['author_ids']);
        $this->assertSame([$this->ids['c_hist']], $book['category_ids']);
        $this->assertTrue(str_contains((string) $book['search_text'], 'كتاب الاختبار'), 'search_text is normalised');
        $this->assertTrue(str_contains((string) $book['search_text'], 'كريم التازي'), 'author name indexed');
        $this->assertSame('وصف <b>غير</b> موثوق', $book['description'], 'stored raw; escaped on output');
        $this->assertSame(1, $this->logCount('book.created'));
        $this->assertSame(null, $this->repo()->findBookBySlug($book['slug']), 'drafts stay private');
    }

    public function testValidationRejectsBadInputWithoutWriting(): void
    {
        $before = (int) Database::scalar('SELECT COUNT(*) FROM books');
        [$id, $v] = BookService::create([
            'title' => '', 'slug' => 'Bad Slug!', 'language' => 'xx', 'visibility' => 'secret',
            'publication_year' => '3000', 'rights_status' => 'stolen', 'author_ids' => ['999999'], 'category_ids' => 'not-an-array',
        ], $this->ids['u_admin'], null);
        $this->assertSame(null, $id);
        foreach (['title', 'slug', 'language', 'visibility', 'publication_year', 'rights_status', 'author_ids', 'category_ids'] as $f) {
            $this->assertTrue(isset($v->errors[$f]), "expected error on $f");
        }
        $this->assertSame($before, (int) Database::scalar('SELECT COUNT(*) FROM books'));
    }

    public function testSlugUniqueness(): void
    {
        [, $v] = BookService::create($this->bookInput(['slug' => 'gardens-river']), $this->ids['u_admin'], null);
        $this->assertTrue(isset($v->errors['slug']));
        [$a] = BookService::create($this->bookInput(['title' => 'Same Title']), $this->ids['u_admin'], null);
        [$b] = BookService::create($this->bookInput(['title' => 'Same Title']), $this->ids['u_admin'], null);
        $this->assertSame('same-title', Book::find($a)['slug']);
        $this->assertSame('same-title-2', Book::find($b)['slug']);
    }

    public function testPublishIsBlockedWithoutRightsOrReadyFile(): void
    {
        [$id] = BookService::create($this->bookInput(['rights_status' => '', 'rights_source' => '']), $this->ids['u_admin'], null);
        $blockers = BookService::publish($id, $this->ids['u_admin'], null);
        $this->assertSame(2, count($blockers), implode(' | ', $blockers));
        $this->assertSame('draft', Book::find($id)['status']);

        BookService::update($id, $this->bookInput(), $this->ids['u_admin'], null);
        LibraryFixtures::attachReadyFile($id, 12, 'valid', 'processing');
        $this->assertSame(1, count(BookService::publish($id, $this->ids['u_admin'], null)), 'render not finished');
        $this->assertSame(0, $this->logCount('book.published'));
    }

    public function testPublishUnpublishArchiveRestoreLifecycle(): void
    {
        [$id] = BookService::create($this->bookInput(['title' => 'Lifecycle Book']), $this->ids['u_admin'], null);
        LibraryFixtures::attachReadyFile($id, 33);
        $this->assertSame([], BookService::publish($id, $this->ids['u_admin'], null));
        $book = Book::find($id);
        $this->assertSame('published', $book['status']);
        $this->assertSame(33, (int) $book['page_count'], 'page count taken from processed file');
        $this->assertTrue($book['published_at'] !== null && $book['rights_verified_at'] !== null);

        $later = new \App\Repositories\MySqlLibraryRepository(static fn () => gmdate('Y-m-d H:i:s', time() + 60));
        $this->assertSame('lifecycle-book', $later->findBookBySlug('lifecycle-book')['slug']);
        $this->assertSame(1, $later->searchBooks(BookQuery::fromArray(['q' => 'lifecycle']))->total);

        BookService::unpublish($id, $this->ids['u_admin'], null);
        $this->assertSame(null, $later->findBookBySlug('lifecycle-book'));
        BookService::archive($id, $this->ids['u_admin'], null);
        $this->assertSame('archived', Book::find($id)['status']);
        $this->assertThrows(DomainException::class, fn () => BookService::unpublish($id, $this->ids['u_admin'], null));
        BookService::restore($id, $this->ids['u_admin'], null);
        $this->assertSame('draft', Book::find($id)['status']);
        foreach (['book.published', 'book.unpublished', 'book.archived', 'book.restored'] as $a) {
            $this->assertSame(1, $this->logCount($a), $a);
        }
    }

    public function testDeactivatingACategoryHidesItEverywhere(): void
    {
        $this->assertSame('active', Database::scalar('SELECT status FROM categories WHERE id = :id', ['id' => $this->ids['c_hist']]));
        CategoryService::toggle($this->ids['c_hist'], $this->ids['u_admin'], null);
        $repo = $this->repo();
        $this->assertSame(null, $repo->findCategoryBySlug('history'));
        $this->assertFalse(in_array('history', array_column($repo->categories(), 'slug'), true));
        $this->assertSame(0, $repo->searchBooks(BookQuery::fromArray(['category' => 'history']))->total);
        $this->assertSame(['literature'], array_column($repo->findBookBySlug('cartographer-notebook')['categories'], 'slug'));
        CategoryService::toggle($this->ids['c_hist'], $this->ids['u_admin'], null);
        $this->assertSame('history', $repo->findCategoryBySlug('history')['slug']);
    }

    public function testCategoryValidationAndCyclePrevention(): void
    {
        [$id, $v] = CategoryService::create(['name_ar' => 'الشعر', 'name_en' => 'Poetry', 'parent_id' => (string) $this->ids['c_lit']], $this->ids['u_admin'], null);
        $this->assertFalse($v->fails());
        $this->assertSame('poetry', Database::scalar('SELECT slug FROM categories WHERE id = :id', ['id' => $id]));
        $v = CategoryService::update($this->ids['c_lit'], ['name_ar' => 'الأدب', 'parent_id' => (string) $id], $this->ids['u_admin'], null);
        $this->assertTrue(isset($v->errors['parent_id']), 'cannot move a parent under its own child');
        [, $v] = CategoryService::create(['name_ar' => '', 'slug' => 'literature'], $this->ids['u_admin'], null);
        $this->assertTrue(isset($v->errors['name_ar']) && isset($v->errors['slug']));
    }

    public function testRenamingAnAuthorReindexesTheirBooks(): void
    {
        $v = AuthorService::update($this->ids['a_karim'], ['name' => 'Karim Tazi Renamed', 'slug' => 'karim-al-tazi'], $this->ids['u_admin'], null);
        $this->assertFalse($v->fails());
        $this->assertSame(['stars-beginners'], array_column($this->repo()->searchBooks(BookQuery::fromArray(['q' => 'renamed']))->items, 'slug'));
        $this->assertSame(0, $this->repo()->searchBooks(BookQuery::fromArray(['q' => 'التازي']))->total);
    }

    public function testTransactionRollsBackOnFailure(): void
    {
        $before = (int) Database::scalar('SELECT COUNT(*) FROM authors');
        $this->assertThrows(\RuntimeException::class, function (): void {
            Database::transaction(function (): void {
                Database::query("INSERT INTO authors (name, slug, created_at, updated_at) VALUES ('Tmp', 'tmp-author', :c, :u)", ['c' => LibraryFixtures::NOW, 'u' => LibraryFixtures::NOW]);
                throw new \RuntimeException('boom');
            });
        });
        $this->assertSame($before, (int) Database::scalar('SELECT COUNT(*) FROM authors'));
    }
}
