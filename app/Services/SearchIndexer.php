<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Maintains books.search_text: a folded (ArabicNormalizer) concatenation of
 * title, authors, category names (AR+EN), publisher and description. Called
 * inside the same transaction whenever a book, or an author/category it uses,
 * changes; `php bin/console search:reindex` rebuilds everything.
 */
final class SearchIndexer
{
    public static function textFor(int $bookId): string
    {
        $book = Database::first('SELECT title, title_en, publisher, description, description_en FROM books WHERE id = :id', ['id' => $bookId]);
        if (!$book) {
            return '';
        }
        $authors = Database::query('SELECT a.name FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = :id ORDER BY ba.author_order', ['id' => $bookId])->fetchAll(\PDO::FETCH_COLUMN);
        $cats = Database::all('SELECT c.name_ar, c.name_en FROM book_categories bc JOIN categories c ON c.id = bc.category_id WHERE bc.book_id = :id', ['id' => $bookId]);

        $parts = [$book['title'], (string) ($book['title_en'] ?? ''), implode(' ', $authors)];
        foreach ($cats as $c) {
            $parts[] = $c['name_ar'] . ' ' . ($c['name_en'] ?? '');
        }
        $parts[] = (string) ($book['publisher'] ?? '');
        $parts[] = (string) ($book['description'] ?? '');
        $parts[] = (string) ($book['description_en'] ?? '');
        return ArabicNormalizer::normalize(implode(' ', $parts));
    }

    public static function reindexBook(int $bookId): void
    {
        Database::query('UPDATE books SET search_text = :t WHERE id = :id', ['t' => self::textFor($bookId), 'id' => $bookId]);
    }

    public static function reindexAuthor(int $authorId): void
    {
        foreach (Database::query('SELECT book_id FROM book_authors WHERE author_id = :a', ['a' => $authorId])->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            self::reindexBook((int) $id);
        }
    }

    public static function reindexCategory(int $categoryId): void
    {
        foreach (Database::query('SELECT book_id FROM book_categories WHERE category_id = :c', ['c' => $categoryId])->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            self::reindexBook((int) $id);
        }
    }

    /** @return int number of books reindexed */
    public static function reindexAll(): int
    {
        $ids = Database::query('SELECT id FROM books ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            self::reindexBook((int) $id);
        }
        return count($ids);
    }
}
