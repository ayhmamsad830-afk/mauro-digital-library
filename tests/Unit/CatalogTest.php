<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Demo\DemoLibraryRepository;
use App\Repositories\UnavailableLibraryRepository;
use App\Services\ArabicNormalizer;
use App\Support\BookQuery;
use App\Support\Paginator;
use Tests\TestCase;

final class CatalogTest extends TestCase
{
    public function testBookQueryWhitelistsAndSanitisesInput(): void
    {
        $q = BookQuery::fromArray([
            'q' => "  hello \x00\n   world  " . str_repeat('x', 300),
            'category' => 'History; DROP TABLE',
            'author' => 'yusuf-benali',
            'language' => 'english',
            'sort' => 'random()',
            'page' => '-4',
            'per_page' => '1000',
        ]);
        $this->assertSame(100, mb_strlen($q->q));
        $this->assertTrue(str_starts_with($q->q, 'hello world'));
        $this->assertSame(null, $q->category);
        $this->assertSame('yusuf-benali', $q->author);
        $this->assertSame(null, $q->language);
        $this->assertSame('newest', $q->sort);
        $this->assertSame(1, $q->page);
        $this->assertSame(24, $q->perPage);
    }

    public function testBookQueryAcceptsValidValuesAndIgnoresArrays(): void
    {
        $q = BookQuery::fromArray(['sort' => 'rating', 'per_page' => '12', 'page' => '3', 'language' => 'ar', 'q' => ['x']]);
        $this->assertSame('rating', $q->sort);
        $this->assertSame(12, $q->perPage);
        $this->assertSame(3, $q->page);
        $this->assertSame('ar', $q->language);
        $this->assertSame('', $q->q);
        $this->assertSame(['language' => 'ar', 'sort' => 'rating', 'per_page' => 12, 'page' => 3], $q->toQuery());
        $this->assertSame(['language' => 'ar', 'sort' => 'rating', 'per_page' => 12], $q->toQuery(['page' => null]));
    }

    public function testPaginatorWindow(): void
    {
        $p = new Paginator([], 240, 6, 24);
        $this->assertSame(10, $p->lastPage());
        $this->assertSame([1, null, 4, 5, 6, 7, 8, null, 10], $p->window());
        $this->assertSame(121, $p->from());
        $this->assertSame(144, $p->to());
        $this->assertSame(1, Paginator::empty()->lastPage());
        $this->assertFalse(Paginator::empty()->hasNext());
    }

    public function testArabicNormalizerFoldsVariants(): void
    {
        $this->assertSame(ArabicNormalizer::normalize('النجوم'), ArabicNormalizer::normalize('النُّجُوم'));
        $this->assertSame(ArabicNormalizer::normalize('مكتبة'), ArabicNormalizer::normalize('مكتبه'));
        $this->assertSame(ArabicNormalizer::normalize('أحمد'), ArabicNormalizer::normalize('احمد'));
        $this->assertSame(ArabicNormalizer::normalize('مستشفى'), ArabicNormalizer::normalize('مستشفي'));
        $this->assertSame('كتاب', ArabicNormalizer::normalize('كتــــاب'));
        $this->assertSame('2026 book', ArabicNormalizer::normalize('٢٠٢٦  BOOK'));
    }

    public function testDemoRepositoryFiltersSortsAndPaginates(): void
    {
        $repo = new DemoLibraryRepository();
        $all = $repo->searchBooks(BookQuery::fromArray([]));
        $this->assertSame(14, $all->total);

        $byCat = $repo->searchBooks(BookQuery::fromArray(['category' => 'history']));
        foreach ($byCat->items as $b) {
            $this->assertTrue(in_array('history', array_column($b['categories'], 'slug'), true));
        }
        $en = $repo->searchBooks(BookQuery::fromArray(['language' => 'en']));
        $this->assertSame(['en'], array_values(array_unique(array_column($en->items, 'language'))));

        $popular = $repo->searchBooks(BookQuery::fromArray(['sort' => 'popular']))->items;
        $this->assertTrue($popular[0]['reads_count'] >= $popular[1]['reads_count']);

        $page2 = $repo->searchBooks(BookQuery::fromArray(['per_page' => '12', 'page' => '2']));
        $this->assertSame(2, count($page2->items));
        $this->assertSame(2, $page2->page);

        // Out-of-range page clamps to the last page instead of an empty list.
        $this->assertSame(2, $repo->searchBooks(BookQuery::fromArray(['per_page' => '12', 'page' => '99']))->page);
    }

    public function testDemoSearchToleratesDiacriticsAndMatchesAuthorsAndCategories(): void
    {
        $repo = new DemoLibraryRepository();
        $stars = $repo->searchBooks(BookQuery::fromArray(['q' => 'النُّجوم']));
        $this->assertTrue(in_array('stars-for-beginners', array_column($stars->items, 'slug'), true));
        $this->assertTrue($repo->searchBooks(BookQuery::fromArray(['q' => 'Nora Castell']))->total >= 2);
        $this->assertTrue($repo->searchBooks(BookQuery::fromArray(['q' => 'التاريخ']))->total >= 2);
        $this->assertSame(0, $repo->searchBooks(BookQuery::fromArray(['q' => 'zzzz-nothing']))->total);
    }

    public function testRelatedBooksExcludeTheBookItself(): void
    {
        $repo = new DemoLibraryRepository();
        $book = $repo->findBookBySlug('gardens-of-the-quiet-river');
        $related = $repo->relatedBooks($book, 6);
        $this->assertTrue($related !== []);
        $this->assertFalse(in_array($book['id'], array_column($related, 'id'), true));
    }

    public function testUnavailableRepositoryReturnsNothing(): void
    {
        $repo = new UnavailableLibraryRepository();
        $this->assertFalse($repo->isDemo());
        $this->assertSame([], $repo->latestBooks(10));
        $this->assertSame(null, $repo->featuredBook());
        $this->assertSame(0, $repo->searchBooks(BookQuery::fromArray([]))->total);
    }
}
