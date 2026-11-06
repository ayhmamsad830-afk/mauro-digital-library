<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\LibraryRepository;
use App\Support\BookQuery;
use App\Support\Paginator;

/**
 * Used until the MySQL repository is implemented (Phase 3) when demo mode is
 * off: every list is empty, so pages render their honest empty states instead
 * of invented content.
 */
final class UnavailableLibraryRepository implements LibraryRepository
{
    public function isDemo(): bool { return false; }
    public function featuredBook(): ?array { return null; }
    public function latestBooks(int $limit): array { return []; }
    public function popularBooks(int $limit): array { return []; }
    public function categories(): array { return []; }
    public function findCategoryBySlug(string $slug): ?array { return null; }
    public function authors(): array { return []; }
    public function languages(): array { return []; }
    public function searchBooks(BookQuery $query): Paginator { return Paginator::empty($query->perPage); }
    public function findBookBySlug(string $slug): ?array { return null; }
    public function findBookById(int $id): ?array { return null; }
    public function relatedBooks(array $book, int $limit): array { return []; }
    public function bookReviews(int $bookId, int $limit): array { return []; }
}
