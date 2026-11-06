<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Services\SearchIndexer;
use App\Validators\CategoryValidator;
use App\Validators\ValidationResult;

final class CategoryService
{
    /** @return array{0: ?int, 1: ValidationResult} */
    public static function create(array $input, int $adminId, ?string $ip): array
    {
        $v = CategoryValidator::validate($input);
        if ($v->fails()) {
            return [null, $v];
        }
        $id = Database::transaction(static function () use ($v, $adminId, $ip): int {
            $id = Category::insert($v->data);
            ActivityLog::record($adminId, 'category.created', 'category', $id, $v->data['name_ar'], $ip);
            return $id;
        });
        return [$id, $v];
    }

    public static function update(int $id, array $input, int $adminId, ?string $ip): ValidationResult
    {
        if (!Category::find($id)) {
            throw new HttpException(404);
        }
        $v = CategoryValidator::validate($input, $id);
        if ($v->fails()) {
            return $v;
        }
        Database::transaction(static function () use ($id, $v, $adminId, $ip): void {
            Category::update($id, $v->data);
            SearchIndexer::reindexCategory($id); // names are part of books.search_text
            ActivityLog::record($adminId, 'category.updated', 'category', $id, $v->data['name_ar'], $ip);
        });
        return $v;
    }

    public static function toggle(int $id, int $adminId, ?string $ip): string
    {
        $cat = Category::find($id);
        if (!$cat) {
            throw new HttpException(404);
        }
        $new = $cat['status'] === 'active' ? 'inactive' : 'active';
        Database::transaction(static function () use ($id, $new, $cat, $adminId, $ip): void {
            Category::setStatus($id, $new);
            ActivityLog::record($adminId, $new === 'active' ? 'category.activated' : 'category.deactivated', 'category', $id, $cat['name_ar'], $ip);
        });
        return $new;
    }
}
