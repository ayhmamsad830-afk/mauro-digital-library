<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Auth;
use App\Services\BookAccessPolicy;
use App\Services\Reader\ReaderSessionService;

/**
 * Reader shell. It only ever receives PAGE IMAGE URL templates — never a PDF
 * URL, a storage path, an original file name or a text layer.
 *
 *  - Real books: the page boots with a manifest skeleton; reader.js then calls
 *    POST /api/reader/sessions (CSRF) to obtain a short-lived signed token and
 *    loads watermarked pages from /api/books/{id}/pages/{n}.
 *  - Demo mode: synthetic GD pages from /api/demo/... (design preview only).
 */
final class ReaderController extends Controller
{
    /** Demo previews generate at most this many synthetic pages. */
    private const DEMO_MAX_PAGES = 24;

    public function show(Request $request): Response
    {
        $library = $this->library();
        $book = $library->findBookBySlug((string) $request->param('slug'));
        if ($book === null || !BookAccessPolicy::canView($book)) {
            throw HttpException::notFound();
        }

        if ($library->isDemo()) {
            $pages = min((int) $book['page_count'], self::DEMO_MAX_PAGES);
            $manifest = [
                'book' => ['id' => $book['id'], 'slug' => $book['slug'], 'title' => $book['title']],
                'fileVersion' => 'demo-1',
                'pageCount' => $pages,
                'pageUrl' => self::template(route('demo.page', ['slug' => $book['slug'], 'page' => 0])),
                'thumbUrl' => self::template(route('demo.thumbnail', ['slug' => $book['slug'], 'page' => 0])),
                'aspect' => 1.414,
                'demo' => true,
            ];
        } else {
            // Throws 503 "not ready" when the current version is not fully rendered.
            $readable = ReaderSessionService::readableBook((int) $book['id']);
            $manifest = [
                'book' => ['id' => $book['id'], 'slug' => $book['slug'], 'title' => $book['title']],
                'fileVersion' => $readable['file']['version'],
                'pageCount' => $readable['file']['pages'],
                'aspect' => 1.414,
                'sessionUrl' => route('api.reader.start'),
                'renewUrl' => route('api.reader.renew'),
                'progressUrl' => Auth::user() ? route('api.progress.store') : null,
                'bookmarkUrl' => Auth::user() ? route('member.bookmark.add') : null,
                'demo' => false,
            ];
        }

        return Response::html(View::render('reader/show', [
            'pageTitle' => __('reader.title', ['title' => $book['title']]),
            'book' => $book,
            'manifest' => $manifest,
            'noindex' => true,
        ], 'layouts/reader'));
    }

    /** "/x/pages/0" → "/x/pages/{n}" (the placeholder must not be URL-encoded). */
    private static function template(string $url): string
    {
        return (string) preg_replace('#/0$#', '/{n}', $url);
    }
}
