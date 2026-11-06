<?php

declare(strict_types=1);

namespace App\Validators;

use App\Models\Slug;

final class AuthorValidator
{
    public static function validate(array $in, ?int $id = null): ValidationResult
    {
        $e = [];
        $d = [
            'name' => Input::str($in, 'name', 255),
            'slug' => strtolower(Input::str($in, 'slug', 191)),
            'biography' => Input::nullable($in, 'biography', 10000),
        ];
        if ($d['name'] === '') {
            $e['name'] = __('validation.required');
        } elseif (mb_strlen($d['name']) > 255) {
            $e['name'] = __('validation.max', ['max' => 255]);
        }
        if ($d['biography'] !== null && mb_strlen($d['biography']) > 10000) {
            $e['biography'] = __('validation.max', ['max' => 10000]);
        }
        if ($d['slug'] === '') {
            if ($d['name'] !== '') {
                $d['slug'] = Slug::unique('authors', Slug::from($d['name']), $id);
            }
        } elseif (!preg_match(Slug::PATTERN, $d['slug']) || strlen($d['slug']) > 191) {
            $e['slug'] = __('validation.slug');
        } elseif (Slug::exists('authors', $d['slug'], $id)) {
            $e['slug'] = __('validation.slug_taken');
        }
        return new ValidationResult($d, $e);
    }
}
