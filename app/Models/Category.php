<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Category
{
    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM categories WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> every category (any status) with total book counts, parents first */
    public static function allWithCounts(): array
    {
        return Database::all(
            'SELECT c.id, c.parent_id, c.slug, c.name_ar, c.name_en, c.sort_order, c.status,
                    p.name_ar AS parent_name_ar, p.name_en AS parent_name_en,
                    (SELECT COUNT(*) FROM book_categories bc WHERE bc.category_id = c.id) AS books_count
               FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
              ORDER BY COALESCE(c.parent_id, c.id) ASC, c.parent_id IS NOT NULL, c.sort_order ASC, c.id ASC'
        );
    }

    /** @return list<array<string,mixed>> */
    public static function options(): array
    {
        return Database::all('SELECT id, parent_id, name_ar, name_en, status FROM categories ORDER BY sort_order ASC, name_ar ASC');
    }

    public static function insert(array $d): int
    {
        $now = gmdate('Y-m-d H:i:s');
        Database::query(
            'INSERT INTO categories (parent_id, name_ar, name_en, slug, description_ar, description_en, sort_order, status, created_at, updated_at)
             VALUES (:parent, :ar, :en, :slug, :dar, :den, :ord, :status, :c, :u)',
            self::params($d) + ['c' => $now, 'u' => $now]
        );
        return Database::lastInsertId();
    }

    public static function update(int $id, array $d): void
    {
        Database::query(
            'UPDATE categories SET parent_id = :parent, name_ar = :ar, name_en = :en, slug = :slug, description_ar = :dar,
                    description_en = :den, sort_order = :ord, status = :status, updated_at = :u WHERE id = :id',
            self::params($d) + ['u' => gmdate('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::query('UPDATE categories SET status = :s, updated_at = :u WHERE id = :id', ['s' => $status, 'u' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
    }

    /** True if $candidateParent is $id itself or one of its descendants (would create a cycle). */
    public static function wouldCycle(int $id, int $candidateParent): bool
    {
        for ($cur = $candidateParent, $guard = 0; $cur !== 0 && $guard < 100; $guard++) {
            if ($cur === $id) {
                return true;
            }
            $cur = (int) Database::scalar('SELECT COALESCE(parent_id, 0) FROM categories WHERE id = :id', ['id' => $cur]);
        }
        return false;
    }

    /** @param list<int> $ids @return list<int> */
    public static function existingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $p] = \App\Repositories\MySqlLibraryRepository::inList($ids, 'c');
        return array_map('intval', Database::query("SELECT id FROM categories WHERE id IN ($in)", $p)->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function params(array $d): array
    {
        return [
            'parent' => $d['parent_id'], 'ar' => $d['name_ar'], 'en' => $d['name_en'], 'slug' => $d['slug'],
            'dar' => $d['description_ar'], 'den' => $d['description_en'], 'ord' => $d['sort_order'], 'status' => $d['status'],
        ];
    }
}
