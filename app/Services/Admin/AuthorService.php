<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Models\ActivityLog;
use App\Models\Author;
use App\Services\SearchIndexer;
use App\Validators\AuthorValidator;
use App\Validators\ValidationResult;

final class AuthorService
{
    /** @return array{0: ?int, 1: ValidationResult} */
    public static function create(array $input, int $adminId, ?string $ip): array
    {
        $v = AuthorValidator::validate($input);
        if ($v->fails()) {
            return [null, $v];
        }
        $id = Database::transaction(static function () use ($v, $adminId, $ip): int {
            $id = Author::insert($v->data);
            ActivityLog::record($adminId, 'author.created', 'author', $id, $v->data['name'], $ip);
            return $id;
        });
        return [$id, $v];
    }

    public static function update(int $id, array $input, int $adminId, ?string $ip): ValidationResult
    {
        if (!Author::find($id)) {
            throw new HttpException(404);
        }
        $v = AuthorValidator::validate($input, $id);
        if ($v->fails()) {
            return $v;
        }
        Database::transaction(static function () use ($id, $v, $adminId, $ip): void {
            Author::update($id, $v->data);
            SearchIndexer::reindexAuthor($id);
            ActivityLog::record($adminId, 'author.updated', 'author', $id, $v->data['name'], $ip);
        });
        return $v;
    }
}
