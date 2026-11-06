<?php

declare(strict_types=1);

namespace App\Support;

/** Result page of a database-paginated query (pagination is done in SQL, spec FR-002). */
final class Paginator
{
    /** @param list<array<string,mixed>> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public static function empty(int $perPage = 24): self
    {
        return new self([], 0, 1, $perPage);
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->lastPage();
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    /**
     * Page numbers to show, with null as an ellipsis: 1 … 4 5 [6] 7 8 … 20
     * @return list<int|null>
     */
    public function window(int $around = 2): array
    {
        $last = $this->lastPage();
        $pages = [];
        $prev = 0;
        for ($i = 1; $i <= $last; $i++) {
            if ($i === 1 || $i === $last || abs($i - $this->page) <= $around) {
                if ($prev && $i - $prev > 1) {
                    $pages[] = null;
                }
                $pages[] = $i;
                $prev = $i;
            }
        }
        return $pages;
    }
}
