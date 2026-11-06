<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $library = $this->library();

        return $this->view('home/index', [
            'pageTitle' => __('home.title'),
            'metaDescription' => __('home.meta_description'),
            'featured' => $library->featuredBook(),
            'latest' => $library->latestBooks((int) Config::get('ui.home_latest', 8)),
            'popular' => $library->popularBooks((int) Config::get('ui.home_popular', 8)),
            'categories' => array_slice($library->categories(), 0, (int) Config::get('ui.home_categories', 8)),
            // "Continue reading" needs an authenticated member (Phase 5); never faked.
            'continueReading' => [],
        ]);
    }

    public function robots(Request $request): Response
    {
        $lines = ['User-agent: *'];
        if (Config::get('app.indexable', false)) {
            foreach (['/account', '/admin', '/api/', '/read/', '/login', '/media/'] as $path) {
                $lines[] = 'Disallow: ' . $path;
            }
            $lines[] = 'Sitemap: ' . self::absolute(route('sitemap'));
        } else {
            $lines[] = 'Disallow: /';
        }
        return new Response(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** sitemap.xml — public pages and PUBLIC books only (never members-only, drafts or scheduled). */
    public function sitemap(Request $request): Response
    {
        $library = $this->library();
        $urls = [];
        foreach (['home', 'books.index', 'books.latest', 'books.popular', 'categories.index', 'authors.index', 'pages.privacy', 'pages.terms', 'pages.copyright'] as $name) {
            $urls[] = ['loc' => self::absolute(route($name)), 'lastmod' => null];
        }
        if ($library instanceof \App\Repositories\MySqlLibraryRepository) {
            $entries = $library->sitemapEntries();
            foreach ($entries['categories'] as $slug) {
                $urls[] = ['loc' => self::absolute(route('categories.show', ['slug' => $slug])), 'lastmod' => null];
            }
            foreach ($entries['books'] as $b) {
                $urls[] = ['loc' => self::absolute(route('books.show', ['slug' => $b['slug']])), 'lastmod' => substr((string) $b['updated_at'], 0, 10)];
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
                . ($u['lastmod'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $u['lastmod']) ? '<lastmod>' . $u['lastmod'] . '</lastmod>' : '') . "</url>\n";
        }
        $xml .= "</urlset>\n";
        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private static function absolute(string $path): string
    {
        $base = (string) Config::get('app.url', '');
        return $base !== '' ? $base . $path : $path;
    }
}
