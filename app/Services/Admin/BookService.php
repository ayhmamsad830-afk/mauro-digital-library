<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Book;
use App\Services\SearchIndexer;
use App\Validators\BookValidator;
use App\Validators\ValidationResult;
use DomainException;

/**
 * Book lifecycle (spec §9.3): Draft → … → Published → Updated → Archived.
 * Every multi-step write runs in one transaction together with its search
 * index update and activity-log entry.
 */
final class BookService
{
    /** @return array{0: ?int, 1: ValidationResult} */
    public static function create(array $input, int $adminId, ?string $ip): array
    {
        $v = BookValidator::validate($input);
        if ($v->fails()) {
            return [null, $v];
        }
        $id = Database::transaction(static function () use ($v, $adminId, $ip): int {
            $id = Book::insert($v->data, $adminId);
            Book::syncAuthors($id, $v->data['author_ids']);
            Book::syncCategories($id, $v->data['category_ids']);
            SearchIndexer::reindexBook($id);
            ActivityLog::record($adminId, 'book.created', 'book', $id, $v->data['title'], $ip);
            return $id;
        });
        return [$id, $v];
    }

    public static function update(int $id, array $input, int $adminId, ?string $ip): ValidationResult
    {
        self::mustExist($id);
        $v = BookValidator::validate($input, $id);
        if ($v->fails()) {
            return $v;
        }
        Database::transaction(static function () use ($id, $v, $adminId, $ip): void {
            Book::update($id, $v->data);
            Book::syncAuthors($id, $v->data['author_ids']);
            Book::syncCategories($id, $v->data['category_ids']);
            SearchIndexer::reindexBook($id);
            ActivityLog::record($adminId, 'book.updated', 'book', $id, $v->data['title'], $ip);
        });
        return $v;
    }

    /**
     * Reasons a book cannot be published yet (empty = publishable). Spec §8.3/§9.3,
     * TC-017/TC-028: complete rights record + a validated, fully rendered PDF version.
     *
     * @return list<string> translated messages
     */
    public static function publishBlockers(array $book): array
    {
        $b = [];
        if (($book['author_ids'] ?? []) === []) {
            $b[] = __('admin.block_authors');
        }
        if (($book['category_ids'] ?? []) === []) {
            $b[] = __('admin.block_categories');
        }
        if (empty($book['rights_status']) || trim((string) ($book['rights_source'] ?? '')) === '') {
            $b[] = __('admin.block_rights');
        }
        $file = $book['current_file'] ?? null;
        if (!$file) {
            $b[] = __('admin.block_no_file');
        } elseif ($file['validation_status'] !== 'valid' || $file['render_status'] !== 'ready' || (int) $file['page_count'] < 1) {
            $b[] = __('admin.block_file_not_ready');
        }
        return $b;
    }

    /** @return list<string> blockers (empty when published) */
    public static function publish(int $id, int $adminId, ?string $ip): array
    {
        $book = self::mustExist($id);
        if ($book['status'] === 'published') {
            return [];
        }
        $blockers = self::publishBlockers($book);
        if ($blockers !== []) {
            return $blockers;
        }
        Database::transaction(static function () use ($id, $book, $adminId, $ip): void {
            // page_count comes from the processed file (spec §9.2 "extracted from PDF").
            Database::query('UPDATE books SET page_count = :pc WHERE id = :id', ['pc' => (int) $book['current_file']['page_count'], 'id' => $id]);
            Book::setStatus($id, 'published', gmdate('Y-m-d H:i:s'), true);
            ActivityLog::record($adminId, 'book.published', 'book', $id, $book['title'], $ip);
        });
        return [];
    }

    public static function unpublish(int $id, int $adminId, ?string $ip): void
    {
        self::transition($id, ['published'], 'draft', 'book.unpublished', $adminId, $ip);
    }

    public static function archive(int $id, int $adminId, ?string $ip): void
    {
        self::transition($id, ['draft', 'published'], 'archived', 'book.archived', $adminId, $ip);
    }

    public static function restore(int $id, int $adminId, ?string $ip): void
    {
        self::transition($id, ['archived'], 'draft', 'book.restored', $adminId, $ip);
    }

    private static function transition(int $id, array $from, string $to, string $action, int $adminId, ?string $ip): void
    {
        $book = self::mustExist($id);
        if (!in_array($book['status'], $from, true)) {
            throw new DomainException(__('admin.invalid_transition'));
        }
        Database::transaction(static function () use ($id, $to, $action, $book, $adminId, $ip): void {
            Book::setStatus($id, $to);
            ActivityLog::record($adminId, $action, 'book', $id, $book['title'], $ip);
        });
    }

    private static function mustExist(int $id): array
    {
        $book = Book::find($id);
        if (!$book) {
            throw new \App\Core\HttpException(404);
        }
        return $book;
    }
}
