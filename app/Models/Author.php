<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Author
{
    public static function find(int $id): ?array
    {
        return Database::first('SELECT id, name, slug, biography, photo_path, created_at, updated_at FROM authors WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> all authors with their total book count (any status) */
    public static function allWithCounts(string $q = ''): array
    {
        $params = [];
        $where = '';
        if ($q !== '') {
            $where = "WHERE a.name LIKE :q ESCAPE '!' OR a.slug LIKE :q2 ESCAPE '!'";
            $params['q'] = '%' . \App\Repositories\MySqlLibraryRepository::escapeLike($q) . '%';
            $params['q2'] = $params['q'];
        }
        return Database::all(
            "SELECT a.id, a.name, a.slug, (SELECT COUNT(*) FROM book_authors ba WHERE ba.author_id = a.id) AS books_count
               FROM authors a $where ORDER BY a.name ASC, a.id ASC",
            $params
        );
    }

    /** @return list<array{id:int,name:string}> */
    public static function options(): array
    {
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], Database::all('SELECT id, name FROM authors ORDER BY name ASC'));
    }

    public static function insert(array $d): int
    {
        $now = gmdate('Y-m-d H:i:s');
        Database::query(
            'INSERT INTO authors (name, slug, biography, created_at, updated_at) VALUES (:name, :slug, :bio, :c, :u)',
            ['name' => $d['name'], 'slug' => $d['slug'], 'bio' => $d['biography'], 'c' => $now, 'u' => $now]
        );
        return Database::lastInsertId();
    }

    public static function update(int $id, array $d): void
    {
        Database::query(
            'UPDATE authors SET name = :name, slug = :slug, biography = :bio, updated_at = :u WHERE id = :id',
            ['name' => $d['name'], 'slug' => $d['slug'], 'bio' => $d['biography'], 'u' => gmdate('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    /** @param list<int> $ids @return list<int> ids that exist */
    public static function existingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $p] = \App\Repositories\MySqlLibraryRepository::inList($ids, 'a');
        return array_map('intval', Database::query("SELECT id FROM authors WHERE id IN ($in)", $p)->fetchAll(\PDO::FETCH_COLUMN));
    }
}
