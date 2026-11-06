<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validated, normalised catalogue query (search + filters + sort + page).
 * Built from untrusted query-string input; every field is whitelisted or
 * pattern-checked so it is safe to pass to a repository.
 */
final class BookQuery
{
    public const SORTS = ['newest', 'popular', 'rating', 'title'];
    public const MAX_QUERY_LENGTH = 100;

    private function __construct(
        public readonly string $q,
        public readonly ?string $category,
        public readonly ?string $author,
        public readonly ?string $language,
        public readonly string $sort,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    /**
     * @param array<string,mixed> $input  usually $request->allQuery()
     * @param list<int> $perPageOptions
     */
    public static function fromArray(array $input, array $perPageOptions = [12, 24, 36], int $perPageDefault = 24, string $defaultSort = 'newest'): self
    {
        $perPage = (int) self::str($input['per_page'] ?? '');
        $sort = self::str($input['sort'] ?? '');
        $page = (int) self::str($input['page'] ?? '1');

        return new self(
            q: self::cleanText(self::str($input['q'] ?? '')),
            category: self::slug(self::str($input['category'] ?? '')),
            author: self::slug(self::str($input['author'] ?? '')),
            language: preg_match('/^[a-z]{2}$/', self::str($input['language'] ?? '')) ? self::str($input['language']) : null,
            sort: in_array($sort, self::SORTS, true) ? $sort : $defaultSort,
            page: max(1, min($page, 10000)),
            perPage: in_array($perPage, $perPageOptions, true) ? $perPage : $perPageDefault,
        );
    }

    public function withPage(int $page): self
    {
        return new self($this->q, $this->category, $this->author, $this->language, $this->sort, max(1, $page), $this->perPage);
    }

    public function withCategory(?string $category): self
    {
        return new self($this->q, $category, $this->author, $this->language, $this->sort, $this->page, $this->perPage);
    }

    public function hasFilters(): bool
    {
        return $this->q !== '' || $this->category !== null || $this->author !== null || $this->language !== null;
    }

    /** Query-string parameters (defaults omitted) used to build links that keep filters. */
    public function toQuery(array $override = [], int $perPageDefault = 24, string $defaultSort = 'newest'): array
    {
        $q = array_filter([
            'q' => $this->q,
            'category' => $this->category,
            'author' => $this->author,
            'language' => $this->language,
            'sort' => $this->sort !== $defaultSort ? $this->sort : null,
            'per_page' => $this->perPage !== $perPageDefault ? $this->perPage : null,
            'page' => $this->page > 1 ? $this->page : null,
        ], static fn ($v) => $v !== null && $v !== '');
        foreach ($override as $k => $v) {
            if ($v === null || $v === '') {
                unset($q[$k]);
            } else {
                $q[$k] = $v;
            }
        }
        return $q;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    private static function slug(string $value): ?string
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) && strlen($value) <= 100 ? $value : null;
    }

    /** Trim, drop control chars, collapse whitespace, cap length (spec §6.4). */
    public static function cleanText(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return mb_substr($value, 0, self::MAX_QUERY_LENGTH);
    }
}
