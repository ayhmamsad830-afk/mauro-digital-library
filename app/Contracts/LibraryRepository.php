<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\BookQuery;
use App\Support\Paginator;

/**
 * Read-side contract for the public library. Controllers depend on this
 * interface only, so the data source can change without touching views:
 *
 *  - DemoLibraryRepository        fictional design-preview data (non-production only)
 *  - UnavailableLibraryRepository empty results until MySQL is connected
 *  - MySqlLibraryRepository       Phase 3 — real data via PDO
 *
 * Book arrays share one shape, documented in docs/ARCHITECTURE.md ("View data shapes").
 * Only PUBLISHED books may ever be returned by implementations.
 */
interface LibraryRepository
{
    public function isDemo(): bool;

    /** @return array<string,mixed>|null */
    public function featuredBook(): ?array;

    /** @return list<array<string,mixed>> */
    public function latestBooks(int $limit): array;

    /** @return list<array<string,mixed>> */
    public function popularBooks(int $limit): array;

    /** Active categories with books_count. @return list<array<string,mixed>> */
    public function categories(): array;

    /** @return array<string,mixed>|null */
    public function findCategoryBySlug(string $slug): ?array;

    /** @return list<array<string,mixed>> */
    public function authors(): array;

    /** Language codes that have at least one published book. @return list<string> */
    public function languages(): array;

    public function searchBooks(BookQuery $query): Paginator;

    /** @return array<string,mixed>|null */
    public function findBookBySlug(string $slug): ?array;

    /** Same visibility rules as findBookBySlug(). @return array<string,mixed>|null */
    public function findBookById(int $id): ?array;

    /** Books sharing a category or author (no "smart" recommendation claims). @return list<array<string,mixed>> */
    public function relatedBooks(array $book, int $limit): array;

    /** Published reviews only. @return list<array<string,mixed>> */
    public function bookReviews(int $bookId, int $limit): array;
}
