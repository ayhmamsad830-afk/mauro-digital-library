<?php

declare(strict_types=1);

namespace App\Validators;

use App\Models\Category;
use App\Models\Slug;

final class CategoryValidator
{
    public static function validate(array $in, ?int $id = null): ValidationResult
    {
        $e = [];
        $parent = Input::str($in, 'parent_id', 20);
        $order = Input::str($in, 'sort_order', 6);
        $d = [
            'name_ar' => Input::str($in, 'name_ar', 191),
            'name_en' => Input::nullable($in, 'name_en', 191),
            'slug' => strtolower(Input::str($in, 'slug', 191)),
            'description_ar' => Input::nullable($in, 'description_ar', 2000),
            'description_en' => Input::nullable($in, 'description_en', 2000),
            'parent_id' => ctype_digit($parent) && (int) $parent > 0 ? (int) $parent : null,
            'sort_order' => preg_match('/^-?\d{1,5}$/', $order) ? (int) $order : 0,
            'status' => Input::str($in, 'status', 10) === 'inactive' ? 'inactive' : 'active',
        ];

        if ($d['name_ar'] === '') {
            $e['name_ar'] = __('validation.required');
        } elseif (mb_strlen($d['name_ar']) > 191) {
            $e['name_ar'] = __('validation.max', ['max' => 191]);
        }
        if ($d['name_en'] !== null && mb_strlen($d['name_en']) > 191) {
            $e['name_en'] = __('validation.max', ['max' => 191]);
        }
        foreach (['description_ar', 'description_en'] as $f) {
            if ($d[$f] !== null && mb_strlen($d[$f]) > 2000) {
                $e[$f] = __('validation.max', ['max' => 2000]);
            }
        }

        if ($d['slug'] === '') {
            $source = $d['name_en'] ?: $d['name_ar'];
            if ($source !== '') {
                $d['slug'] = Slug::unique('categories', Slug::from($source), $id);
            }
        } elseif (!preg_match(Slug::PATTERN, $d['slug']) || strlen($d['slug']) > 191) {
            $e['slug'] = __('validation.slug');
        } elseif (Slug::exists('categories', $d['slug'], $id)) {
            $e['slug'] = __('validation.slug_taken');
        }

        if ($d['parent_id'] !== null) {
            if (!Category::find($d['parent_id'])) {
                $e['parent_id'] = __('validation.invalid');
            } elseif ($id !== null && Category::wouldCycle($id, $d['parent_id'])) {
                $e['parent_id'] = __('validation.category_cycle');
            }
        }

        return new ValidationResult($d, $e);
    }
}
