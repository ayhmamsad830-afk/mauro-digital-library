<?php

declare(strict_types=1);

namespace App\Validators;

use App\Models\Author;
use App\Models\Category;
use App\Models\Slug;

/** Book metadata form (spec §9.2). File, cover and status are handled by dedicated actions. */
final class BookValidator
{
    public const LANGUAGES = ['ar', 'en', 'fr'];
    public const VISIBILITIES = ['public', 'members'];
    public const RIGHTS = ['owned', 'licensed', 'public_domain'];

    public static function validate(array $in, ?int $bookId = null): ValidationResult
    {
        $e = [];
        $d = [
            'title' => Input::str($in, 'title', 255),
            'slug' => strtolower(Input::str($in, 'slug', 191)),
            'description' => Input::nullable($in, 'description', 20000),
            'language' => Input::str($in, 'language', 10),
            'publisher' => Input::nullable($in, 'publisher', 255),
            'publication_year' => Input::str($in, 'publication_year', 4),
            'visibility' => Input::str($in, 'visibility', 20),
            'is_featured' => Input::bool($in, 'is_featured'),
            'rights_status' => Input::nullable($in, 'rights_status', 20),
            'license_name' => Input::nullable($in, 'license_name', 255),
            'rights_holder' => Input::nullable($in, 'rights_holder', 255),
            'rights_source' => Input::nullable($in, 'rights_source', 2000),
            'author_ids' => Input::ids($in, 'author_ids'),
            'category_ids' => Input::ids($in, 'category_ids'),
        ];

        if ($d['title'] === '') {
            $e['title'] = __('validation.required');
        } elseif (mb_strlen($d['title']) > 255) {
            $e['title'] = __('validation.max', ['max' => 255]);
        }

        if ($d['slug'] === '' && $d['title'] !== '') {
            $d['slug'] = Slug::unique('books', Slug::from($d['title']), $bookId);
        } elseif ($d['slug'] !== '' && (!preg_match(Slug::PATTERN, $d['slug']) || strlen($d['slug']) > 191)) {
            $e['slug'] = __('validation.slug');
        } elseif ($d['slug'] !== '' && Slug::exists('books', $d['slug'], $bookId)) {
            $e['slug'] = __('validation.slug_taken');
        }

        foreach (['description' => 20000, 'publisher' => 255, 'license_name' => 255, 'rights_holder' => 255, 'rights_source' => 2000] as $f => $max) {
            if ($d[$f] !== null && mb_strlen($d[$f]) > $max) {
                $e[$f] = __('validation.max', ['max' => $max]);
            }
        }
        if (!in_array($d['language'], self::LANGUAGES, true)) {
            $e['language'] = __('validation.invalid');
        }
        if (!in_array($d['visibility'], self::VISIBILITIES, true)) {
            $e['visibility'] = __('validation.invalid');
        }
        if ($d['rights_status'] !== null && !in_array($d['rights_status'], self::RIGHTS, true)) {
            $e['rights_status'] = __('validation.invalid');
        }

        if ($d['publication_year'] === '') {
            $d['publication_year'] = null;
        } elseif (!ctype_digit($d['publication_year']) || (int) $d['publication_year'] < 1 || (int) $d['publication_year'] > (int) gmdate('Y') + 1) {
            $e['publication_year'] = __('validation.year');
        } else {
            $d['publication_year'] = (int) $d['publication_year'];
        }

        if ($d['author_ids'] === []) {
            $e['author_ids'] = __('validation.choose_one');
        } elseif (count(Author::existingIds($d['author_ids'])) !== count($d['author_ids'])) {
            $e['author_ids'] = __('validation.invalid');
        }
        if ($d['category_ids'] === []) {
            $e['category_ids'] = __('validation.choose_one');
        } elseif (count(Category::existingIds($d['category_ids'])) !== count($d['category_ids'])) {
            $e['category_ids'] = __('validation.invalid');
        }

        return new ValidationResult($d, $e);
    }
}
