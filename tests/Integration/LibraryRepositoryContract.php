<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\MySqlLibraryRepository;
use App\Support\BookQuery;
use Tests\Support\LibraryFixtures;

/**
 * Behaviour every database backend must satisfy for the public catalogue.
 * Used by SqliteLibraryTest (logic harness) and MySqlLibraryTest (real MySQL).
 *
 * @property array<string,int> $ids
 */
trait LibraryRepositoryContract
{
    protected array $ids = [];

    /** Repository as seen by a signed-in MEMBER (members-only books visible). */
    protected function repo(): MySqlLibraryRepository
    {
        return new MySqlLibraryRepository(static fn () => LibraryFixtures::NOW, static fn () => true);
    }

    /** Repository as seen by an unauthenticated GUEST. */
    protected function guestRepo(): MySqlLibraryRepository
    {
        return new MySqlLibraryRepository(static fn () => LibraryFixtures::NOW, static fn () => false);
    }

    private function q(array $in, array $perPageOptions = [12, 24, 36], int $default = 24): BookQuery
    {
        return BookQuery::fromArray($in, $perPageOptions, $default);
    }

    private function slugs(array $books): array
    {
        return array_column($books, 'slug');
    }

    // ---------------------------------------------------------- visibility

    public function testOnlyPublishedPastNonDeletedBooksAreVisible(): void
    {
        $all = $this->repo()->searchBooks($this->q([]));
        $this->assertSame(5, $all->total);
        $visible = $this->slugs($all->items);
        sort($visible);
        $this->assertSame(['alexandria-school', 'cartographer-notebook', 'gardens-river', 'pure-data', 'stars-beginners'], $visible);
        foreach (['secret-draft', 'archived-book', 'scheduled-book', 'deleted-book'] as $hidden) {
            $this->assertSame(null, $this->repo()->findBookBySlug($hidden), "$hidden must be invisible");
        }
        $this->assertSame('gardens-river', $this->repo()->findBookBySlug('gardens-river')['slug']);
    }

    public function testInvisibleBooksDoNotLeakIntoCountsListsOrRelated(): void
    {
        $repo = $this->repo();
        $this->assertFalse(in_array('secret-draft', $this->slugs($repo->popularBooks(50)), true), 'draft has 999 reads but must not rank');
        $lit = $repo->findCategoryBySlug('literature');
        $this->assertSame(2, $lit['books_count'], 'only b1 + b2 are visible in literature');
        $related = $this->slugs($repo->relatedBooks($repo->findBookBySlug('gardens-river'), 10));
        sort($related);
        $this->assertSame(['alexandria-school', 'cartographer-notebook'], $related);
        $this->assertSame(['ar', 'en'], $repo->languages());
    }

    // ------------------------------------------------- members-only (guests)

    public function testGuestsCannotDiscoverMembersOnlyBooksAnywhere(): void
    {
        $g = $this->guestRepo();
        $slug = 'alexandria-school'; // b4, visibility = members
        $this->assertSame(null, $g->findBookBySlug($slug), 'book details');
        $this->assertSame(4, $g->searchBooks($this->q([]))->total, 'catalogue');
        $this->assertFalse(in_array($slug, $this->slugs($g->searchBooks($this->q([]))->items), true));
        $this->assertSame(0, $g->searchBooks($this->q(['q' => 'الاسكندرية']))->total, 'search by title');
        $this->assertSame(['gardens-river'], $this->slugs($g->searchBooks($this->q(['author' => 'salma-al-haddad']))->items), 'author filter');
        $this->assertSame(['cartographer-notebook'], $this->slugs($g->searchBooks($this->q(['category' => 'history']))->items), 'category listing');
        $this->assertFalse(in_array($slug, $this->slugs($g->latestBooks(50)), true), 'latest');
        $this->assertFalse(in_array($slug, $this->slugs($g->popularBooks(50)), true), 'popular');
        $this->assertSame(['cartographer-notebook'], $this->slugs($g->relatedBooks($g->findBookBySlug('gardens-river'), 10)), 'related');
        $this->assertSame(1, $g->findCategoryBySlug('history')['books_count'], 'category counts');
        $this->assertSame(1, array_column($g->authors(), 'books_count', 'slug')['salma-al-haddad'], 'author counts');
        Database::query("UPDATE settings SET setting_value = :v WHERE setting_key = 'home.featured_book_id'", ['v' => (string) $this->ids['b4']]);
        $this->assertSame('gardens-river', $g->featuredBook()['slug'], 'featured falls back for guests');
    }

    public function testAuthorWithOnlyMembersBooksIsHiddenFromGuests(): void
    {
        Database::query('DELETE FROM book_authors WHERE book_id = :b', ['b' => $this->ids['b4']]);
        Database::query('INSERT INTO book_authors (book_id, author_id, author_order) VALUES (:b, :a, 1)', ['b' => $this->ids['b4'], 'a' => $this->ids['a_ghost']]);
        $this->assertFalse(isset(array_column($this->guestRepo()->authors(), 'slug', 'slug')['ghost-writer']));
        $this->assertTrue(isset(array_column($this->repo()->authors(), 'slug', 'slug')['ghost-writer']));
    }

    public function testMembersSeeMembersOnlyBooks(): void
    {
        $m = $this->repo();
        $this->assertSame('members', $m->findBookBySlug('alexandria-school')['visibility']);
        $this->assertSame(1, $m->searchBooks($this->q(['q' => 'الاسكندرية']))->total);
        $this->assertSame(2, $m->findCategoryBySlug('history')['books_count']);
    }

    public function testDefaultViewerIsTheSessionUser(): void
    {
        $_SESSION = [];
        \App\Services\Auth::reset();
        $default = new MySqlLibraryRepository(static fn () => LibraryFixtures::NOW);
        $this->assertSame(null, $default->findBookBySlug('alexandria-school'), 'no session → guest');
        $_SESSION['user_id'] = $this->ids['u_member'];
        \App\Services\Auth::reset();
        $this->assertSame('alexandria-school', $default->findBookBySlug('alexandria-school')['slug'], 'active member');
        Database::query("UPDATE users SET status = 'suspended' WHERE id = :id", ['id' => $this->ids['u_member']]);
        \App\Services\Auth::reset();
        $this->assertSame(null, $default->findBookBySlug('alexandria-school'), 'suspended account → guest');
        $_SESSION = [];
        \App\Services\Auth::reset();
    }

    public function testScheduledBookAppearsOnceItsTimeArrives(): void
    {
        $later = new MySqlLibraryRepository(static fn () => '2027-01-02 00:00:00');
        $this->assertSame('scheduled-book', $later->findBookBySlug('scheduled-book')['slug']);
    }

    // -------------------------------------------------------------- lists

    public function testLatestAndPopularOrdering(): void
    {
        $this->assertSame(['gardens-river', 'cartographer-notebook', 'stars-beginners'], $this->slugs($this->repo()->latestBooks(3)));
        $this->assertSame(['cartographer-notebook', 'gardens-river', 'stars-beginners', 'alexandria-school', 'pure-data'], $this->slugs($this->repo()->popularBooks(10)));
    }

    public function testFeaturedBookFromSettingOrFlag(): void
    {
        $this->assertSame('gardens-river', $this->repo()->featuredBook()['slug']);
        Database::query("UPDATE settings SET setting_value = :v WHERE setting_key = 'home.featured_book_id'", ['v' => (string) $this->ids['b2']]);
        $this->assertSame('cartographer-notebook', $this->repo()->featuredBook()['slug']);
        // A non-visible chosen book falls back to the flagged one.
        Database::query("UPDATE settings SET setting_value = :v WHERE setting_key = 'home.featured_book_id'", ['v' => (string) $this->ids['b5']]);
        $this->assertSame('gardens-river', $this->repo()->featuredBook()['slug']);
    }

    public function testHydratedShapeAuthorsAndActiveCategoriesOnly(): void
    {
        $b = $this->repo()->findBookBySlug('gardens-river');
        $this->assertSame('سلمى الحداد', $b['authors'][0]['name']);
        $this->assertSame(['literature'], array_column($b['categories'], 'slug'), 'inactive category must be dropped');
        $this->assertSame(100, $b['reads_count']);
        $this->assertSame(4.6, $b['rating_avg']);
        $this->assertTrue(is_int($b['id']) && is_int($b['cover_tone']));
    }

    public function testCategoriesAuthorsAndTaxonomyLookups(): void
    {
        $repo = $this->repo();
        $this->assertSame(['literature', 'history', 'science', 'astronomy'], array_column($repo->categories(), 'slug'));
        $this->assertSame(null, $repo->findCategoryBySlug('hidden-cat'));
        $authors = array_column($repo->authors(), 'books_count', 'slug');
        $this->assertSame(['karim-al-tazi' => 1, 'nora-castell' => 2, 'salma-al-haddad' => 2], (function ($a) { ksort($a); return $a; })($authors));
        $this->assertFalse(isset($authors['ghost-writer']), 'author with only a draft is hidden');
    }

    public function testReviewsArePublishedFromActiveUsersOnly(): void
    {
        $reviews = $this->repo()->bookReviews($this->ids['b1'], 10);
        $this->assertSame(['Visible review'], array_column($reviews, 'comment'));
    }

    // ------------------------------------------------------------- search

    public function testArabicSearchIgnoresDiacriticsBothWays(): void
    {
        $this->assertSame(['stars-beginners'], $this->slugs($this->repo()->searchBooks($this->q(['q' => 'النجوم']))->items));
        $this->assertSame(['stars-beginners'], $this->slugs($this->repo()->searchBooks($this->q(['q' => 'النُّجُوم']))->items));
    }

    public function testArabicLetterVariantsAndPartialTitles(): void
    {
        $this->assertSame(['gardens-river'], $this->slugs($this->repo()->searchBooks($this->q(['q' => 'النه']))->items), 'partial word of the title');
        $this->assertSame(['gardens-river'], $this->slugs($this->repo()->searchBooks($this->q(['q' => 'حدايق']))->items), 'ئ folded to ي');
        $salma = $this->slugs($this->repo()->searchBooks($this->q(['q' => 'سلمي']))->items); // ي instead of ى
        sort($salma);
        $this->assertSame(['alexandria-school', 'gardens-river'], $salma);
        $this->assertSame(['alexandria-school'], $this->slugs($this->repo()->searchBooks($this->q(['q' => 'الاسكندريه']))->items), 'إ→ا and ة→ه');
    }

    public function testEnglishSearchIsCaseInsensitiveAndMatchesAuthors(): void
    {
        $this->assertSame(['cartographer-notebook'], $this->slugs($this->repo()->searchBooks($this->q(['q' => 'CARTOGRAPHER']))->items));
        $nora = $this->slugs($this->repo()->searchBooks($this->q(['q' => 'castell']))->items);
        sort($nora);
        $this->assertSame(['cartographer-notebook', 'pure-data'], $nora);
    }

    public function testMultipleTokensMustAllMatch(): void
    {
        $this->assertSame(1, $this->repo()->searchBooks($this->q(['q' => 'cartographer notebook']))->total);
        $this->assertSame(0, $this->repo()->searchBooks($this->q(['q' => 'cartographer zzz']))->total);
        $this->assertSame(2, $this->repo()->searchBooks($this->q(['q' => 'التاريخ']))->total, 'category names are searchable');
    }

    public function testLikeWildcardsAreLiteral(): void
    {
        $this->assertSame(['pure-data'], $this->slugs($this->repo()->searchBooks($this->q(['q' => '%']))->items));
        $this->assertSame(['pure-data'], $this->slugs($this->repo()->searchBooks($this->q(['q' => '_']))->items));
        $this->assertSame(0, $this->repo()->searchBooks($this->q(['q' => '!']))->total);
    }

    public function testEmptyResults(): void
    {
        $r = $this->repo()->searchBooks($this->q(['q' => 'zzzz-no-such-book']));
        $this->assertSame(0, $r->total);
        $this->assertSame([], $r->items);
        $this->assertSame(1, $r->page);
        $this->assertSame(0, $this->repo()->searchBooks($this->q(['author' => 'ghost-writer']))->total);
        $this->assertSame(0, $this->repo()->searchBooks($this->q(['category' => 'hidden-cat']))->total);
    }

    // ------------------------------------------------------------ filters

    public function testFiltersByCategoryIncludingChildrenAuthorAndLanguage(): void
    {
        $sci = $this->slugs($this->repo()->searchBooks($this->q(['category' => 'science']))->items);
        sort($sci);
        $this->assertSame(['pure-data', 'stars-beginners'], $sci, 'parent includes direct sub-category');
        $this->assertSame(['stars-beginners'], $this->slugs($this->repo()->searchBooks($this->q(['category' => 'astronomy']))->items));
        $salma = $this->slugs($this->repo()->searchBooks($this->q(['author' => 'salma-al-haddad']))->items);
        sort($salma);
        $this->assertSame(['alexandria-school', 'gardens-river'], $salma);
        $en = $this->slugs($this->repo()->searchBooks($this->q(['language' => 'en']))->items);
        sort($en);
        $this->assertSame(['cartographer-notebook', 'pure-data'], $en);
        $this->assertSame(['cartographer-notebook'], $this->slugs($this->repo()->searchBooks($this->q(['language' => 'en', 'category' => 'history']))->items));
    }

    // ------------------------------------------------------ sort/paginate

    public function testSortingOptions(): void
    {
        $this->assertSame(['stars-beginners', 'gardens-river', 'cartographer-notebook'], array_slice($this->slugs($this->repo()->searchBooks($this->q(['sort' => 'rating']))->items), 0, 3));
        $this->assertSame(['pure-data', 'cartographer-notebook', 'stars-beginners', 'gardens-river', 'alexandria-school'], $this->slugs($this->repo()->searchBooks($this->q(['sort' => 'title']))->items));
        $this->assertSame('cartographer-notebook', $this->repo()->searchBooks($this->q(['sort' => 'popular']))->items[0]['slug']);
        $this->assertSame('gardens-river', $this->repo()->searchBooks($this->q(['sort' => 'DROP TABLE']))->items[0]['slug'], 'unknown sort → newest');
    }

    public function testPaginationIsDoneInSqlAndClamped(): void
    {
        $p1 = $this->repo()->searchBooks($this->q(['per_page' => '2'], [2], 2));
        $this->assertSame([5, 3, 2], [$p1->total, $p1->lastPage(), count($p1->items)]);
        $p3 = $this->repo()->searchBooks($this->q(['per_page' => '2', 'page' => '3'], [2], 2));
        $this->assertSame(1, count($p3->items));
        $this->assertSame('alexandria-school', $p3->items[0]['slug'], 'newest order: b1,b2,b3,b9,b4');
        $p99 = $this->repo()->searchBooks($this->q(['per_page' => '2', 'page' => '99'], [2], 2));
        $this->assertSame(3, $p99->page, 'out-of-range page clamps to last page');
        $all = array_merge($p1->items, $this->repo()->searchBooks($this->q(['per_page' => '2', 'page' => '2'], [2], 2))->items, $p3->items);
        $this->assertSame(5, count(array_unique(array_column($all, 'id'))), 'pages do not overlap');
    }

    // -------------------------------------------------------- injection

    public function testSqlInjectionPayloadsAreInert(): void
    {
        $repo = $this->repo();
        foreach (["' OR 1=1 --", "'; DROP TABLE books; --", '" OR ""="', "1) OR (1=1", "\\' OR 1=1 #", "%' OR '%'='"] as $payload) {
            $this->assertSame(0, $repo->searchBooks($this->q(['q' => $payload]))->total, "search payload: $payload");
            $this->assertSame(null, $repo->findBookBySlug($payload));
            $this->assertSame(null, $repo->findCategoryBySlug($payload));
        }
        // Filters never reach SQL unless they pass the slug whitelist.
        $this->assertSame(5, $repo->searchBooks($this->q(['category' => "x' OR '1'='1", 'author' => '1 OR 1=1']))->total);
        $this->assertSame(9, (int) Database::scalar('SELECT COUNT(*) FROM books'), 'books table intact');
    }
}
