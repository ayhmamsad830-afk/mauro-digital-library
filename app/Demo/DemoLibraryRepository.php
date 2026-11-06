<?php

declare(strict_types=1);

namespace App\Demo;

use App\Contracts\LibraryRepository;
use App\Services\ArabicNormalizer;
use App\Support\BookQuery;
use App\Support\Paginator;

/**
 * In-memory implementation over DemoData (fictional). Mirrors the behaviour the
 * MySQL repository must have in Phase 3 (filters, sorting, pagination, search
 * normalisation) so the UI can be built and tested end-to-end now.
 */
final class DemoLibraryRepository implements LibraryRepository
{
    /** @var list<array<string,mixed>>|null */
    private ?array $books = null;

    public function isDemo(): bool
    {
        return true;
    }

    /** Books hydrated with author/category objects (same shape as Phase 3). */
    private function books(): array
    {
        if ($this->books !== null) {
            return $this->books;
        }
        $authors = array_column(DemoData::authors(), null, 'id');
        $cats = array_column(DemoData::categories(), null, 'id');
        $this->books = array_map(static function (array $b) use ($authors, $cats): array {
            $b['visibility'] ??= 'public';
            $b['authors'] = array_values(array_map(static fn ($id) => $authors[$id], $b['author_ids']));
            $b['categories'] = array_values(array_map(static fn ($id) => $cats[$id], $b['category_ids']));
            unset($b['author_ids'], $b['category_ids']);
            return $b;
        }, DemoData::books());
        return $this->books;
    }

    /** Books the current viewer may see (members-only hidden from guests, same rule as MySQL). */
    private function visibleBooks(): array
    {
        return array_values(array_filter($this->books(), static fn (array $b): bool => \App\Services\BookAccessPolicy::canView($b)));
    }

    public function featuredBook(): ?array
    {
        foreach ($this->visibleBooks() as $b) {
            if ($b['is_featured']) {
                return $b;
            }
        }
        return null;
    }

    public function latestBooks(int $limit): array
    {
        return array_slice($this->sorted($this->visibleBooks(), 'newest'), 0, $limit);
    }

    public function popularBooks(int $limit): array
    {
        return array_slice($this->sorted($this->visibleBooks(), 'popular'), 0, $limit);
    }

    public function categories(): array
    {
        return array_map(function (array $c): array {
            $c['books_count'] = count(array_filter($this->visibleBooks(), static fn ($b) => in_array($c['slug'], array_column($b['categories'], 'slug'), true)));
            return $c;
        }, DemoData::categories());
    }

    public function findCategoryBySlug(string $slug): ?array
    {
        foreach ($this->categories() as $c) {
            if ($c['slug'] === $slug) {
                return $c;
            }
        }
        return null;
    }

    public function authors(): array
    {
        return array_map(function (array $a): array {
            $a['books_count'] = count(array_filter($this->visibleBooks(), static fn ($b) => in_array($a['slug'], array_column($b['authors'], 'slug'), true)));
            return $a;
        }, DemoData::authors());
    }

    public function languages(): array
    {
        $langs = array_values(array_unique(array_column($this->visibleBooks(), 'language')));
        sort($langs);
        return $langs;
    }

    public function searchBooks(BookQuery $query): Paginator
    {
        $needle = ArabicNormalizer::normalize($query->q);
        $items = array_values(array_filter($this->visibleBooks(), static function (array $b) use ($query, $needle): bool {
            if ($query->category && !in_array($query->category, array_column($b['categories'], 'slug'), true)) {
                return false;
            }
            if ($query->author && !in_array($query->author, array_column($b['authors'], 'slug'), true)) {
                return false;
            }
            if ($query->language && $b['language'] !== $query->language) {
                return false;
            }
            if ($needle !== '') {
                $hay = ArabicNormalizer::normalize(implode(' ', [
                    $b['title'], $b['description'],
                    implode(' ', array_column($b['authors'], 'name')),
                    implode(' ', array_column($b['categories'], 'name_ar')),
                    implode(' ', array_column($b['categories'], 'name_en')),
                ]));
                return str_contains($hay, $needle);
            }
            return true;
        }));

        $items = $this->sorted($items, $query->sort);
        $total = count($items);
        $page = min($query->page, max(1, (int) ceil($total / $query->perPage)));
        return new Paginator(array_slice($items, ($page - 1) * $query->perPage, $query->perPage), $total, $page, $query->perPage);
    }

    public function findBookBySlug(string $slug): ?array
    {
        foreach ($this->visibleBooks() as $b) {
            if ($b['slug'] === $slug) {
                return $b;
            }
        }
        return null;
    }

    public function findBookById(int $id): ?array
    {
        foreach ($this->visibleBooks() as $b) {
            if ($b['id'] === $id) {
                return $b;
            }
        }
        return null;
    }

    public function relatedBooks(array $book, int $limit): array
    {
        $cats = array_column($book['categories'], 'slug');
        $authors = array_column($book['authors'], 'slug');
        $related = array_filter($this->visibleBooks(), static fn ($b) => $b['id'] !== $book['id'] && (
            array_intersect($cats, array_column($b['categories'], 'slug')) || array_intersect($authors, array_column($b['authors'], 'slug'))
        ));
        return array_slice($this->sorted(array_values($related), 'popular'), 0, $limit);
    }

    public function bookReviews(int $bookId, int $limit): array
    {
        return array_slice(array_values(array_filter(DemoData::reviews(), static fn ($r) => $r['book_id'] === $bookId)), 0, $limit);
    }

    /** @param list<array<string,mixed>> $books */
    private function sorted(array $books, string $sort): array
    {
        usort($books, static fn (array $a, array $b): int => match ($sort) {
            'popular' => $b['reads_count'] <=> $a['reads_count'],
            'rating' => [$b['rating_avg'], $b['rating_count']] <=> [$a['rating_avg'], $a['rating_count']],
            'title' => strcmp($a['title'], $b['title']),
            default => strcmp($b['published_at'], $a['published_at']),
        });
        return $books;
    }
}
