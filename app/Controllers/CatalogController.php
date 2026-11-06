<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Support\BookQuery;

/**
 * Catalogue listings: /books, /latest, /popular, /search, /categories/{slug}.
 * All share one view; they differ only in default sort, heading and base URL.
 */
final class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->listing($request, 'books.index', [], __('catalog.title'), __('catalog.subtitle'));
    }

    public function latest(Request $request): Response
    {
        return $this->listing($request, 'books.latest', [], __('catalog.latest_title'), __('catalog.latest_subtitle'), 'newest');
    }

    public function popular(Request $request): Response
    {
        return $this->listing($request, 'books.popular', [], __('catalog.popular_title'), __('catalog.popular_subtitle'), 'popular');
    }

    public function search(Request $request): Response
    {
        $q = BookQuery::cleanText((string) $request->query('q', ''));
        if ($q !== '' && !$this->library()->isDemo()) {
            \App\Services\RateLimiter::enforce('search', 'ip:' . $request->ip()); // spec §13: search abuse
        }
        $title = $q !== '' ? __('search.results_for', ['q' => $q]) : __('search.title');
        return $this->listing($request, 'search', [], $title, null);
    }

    public function category(Request $request): Response
    {
        $category = $this->library()->findCategoryBySlug((string) $request->param('slug'));
        if ($category === null) {
            throw HttpException::notFound();
        }
        return $this->listing($request, 'categories.show', ['slug' => $category['slug']], localized($category, 'name'),
            localized($category, 'description') ?: null, 'newest', $category);
    }

    public function categories(Request $request): Response
    {
        return $this->view('library/categories', [
            'pageTitle' => __('categories.title'),
            'metaDescription' => __('categories.meta_description'),
            'categories' => $this->library()->categories(),
        ]);
    }

    public function authors(Request $request): Response
    {
        return $this->view('library/authors', [
            'pageTitle' => __('authors.title'),
            'authors' => $this->library()->authors(),
        ]);
    }

    private function listing(Request $request, string $routeName, array $routeParams, string $title, ?string $subtitle,
        string $defaultSort = 'newest', ?array $category = null): Response
    {
        $perPageDefault = (int) Config::get('ui.per_page_default', 24);
        if (!$this->library()->isDemo()) { // admin setting catalog.per_page (must be one of the offered options)
            $chosen = (int) \App\Models\Setting::get('catalog.per_page', (string) $perPageDefault);
            $perPageDefault = in_array($chosen, Config::get('ui.per_page_options', [12, 24, 36]), true) ? $chosen : $perPageDefault;
        }
        $query = BookQuery::fromArray($request->allQuery(), Config::get('ui.per_page_options', [12, 24, 36]), $perPageDefault, $defaultSort);
        if ($category !== null) {
            $query = $query->withCategory($category['slug']);
        }

        $library = $this->library();
        $results = $library->searchBooks($query);

        $baseUrl = route($routeName, $routeParams);
        $urlFor = static function (array $override = []) use ($query, $baseUrl, $perPageDefault, $defaultSort, $category): string {
            $params = $query->toQuery($override + ['page' => null], $perPageDefault, $defaultSort);
            if ($category !== null) {
                unset($params['category']); // implied by the URL
            }
            return $baseUrl . ($params ? '?' . http_build_query($params) : '');
        };

        return $this->view('library/catalog', [
            'pageTitle' => $title,
            'metaDescription' => $subtitle ?? __('catalog.subtitle'),
            'heading' => $title,
            'subtitle' => $subtitle,
            'routeName' => $routeName,
            'formAction' => $baseUrl,
            'query' => $query,
            'results' => $results,
            'defaultSort' => $defaultSort,
            'lockedCategory' => $category,
            'categories' => $library->categories(),
            'authors' => $library->authors(),
            'languages' => $library->languages(),
            'perPageOptions' => Config::get('ui.per_page_options', [12, 24, 36]),
            'urlFor' => $urlFor,
            'pageUrl' => static fn (int $p): string => $urlFor(['page' => $p > 1 ? $p : null]),
            'clearUrl' => $baseUrl,
            'noindex' => $routeName === 'search',
        ]);
    }
}
