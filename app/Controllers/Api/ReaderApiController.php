<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;
use App\Services\RateLimiter;
use App\Services\ReadingProgressService;
use App\Services\Reader\PageDeliveryService;
use App\Services\Reader\ReaderSessionService;
use App\Services\Reader\ReaderTokenService;

/**
 * Protected reader API (spec §7.5, §12.2). There is no endpoint that returns
 * the original PDF, its path or its original file name.
 *
 *  POST /api/reader/sessions          start/reuse a reading session → manifest + signed token (CSRF)
 *  POST /api/reader/sessions/renew    fresh short-lived token for the same session (CSRF)
 *  GET  /api/books/{id}/reader-manifest?rs&exp&sig
 *  GET  /api/books/{id}/pages/{n}?rs&exp&sig        watermarked WebP
 *  GET  /api/books/{id}/thumbnails/{n}?rs&exp&sig   watermarked WebP thumbnail
 */
final class ReaderApiController extends Controller
{
    public function start(Request $request): Response
    {
        RateLimiter::enforce('reader_sessions', 'ip:' . $request->ip());
        $bookId = (int) $request->input('book_id', 0);
        $r = ReaderSessionService::readableBook($bookId);
        $session = ReaderSessionService::start($r['book'], $r['file'], $request);
        return $this->json($this->buildManifest($r, $session['id'], true));
    }

    public function renew(Request $request): Response
    {
        $rs = (int) $request->input('rs', 0);
        $bookId = (int) $request->input('book_id', 0);
        $mine = (array) \App\Core\Session::get('_reader_sessions', []);
        // Only a session created in THIS browser session can be renewed.
        if ($rs < 1 || !in_array($rs, array_map('intval', $mine), true)) {
            throw new HttpException(403, __('reader.token_invalid'));
        }
        $r = ReaderSessionService::readableBook($bookId);
        $row = \App\Core\Database::first('SELECT book_id, book_file_id, ended_at, expires_at FROM reading_sessions WHERE id = :id', ['id' => $rs]);
        if (!$row || (int) $row['book_id'] !== $bookId || $row['ended_at'] !== null || (string) $row['expires_at'] <= gmdate('Y-m-d H:i:s')) {
            throw new HttpException(401, __('reader.token_expired'));
        }
        if ((int) $row['book_file_id'] !== $r['file']['id']) {
            throw new HttpException(409, __('reader.version_changed'));
        }
        return $this->json(['token' => ['rs' => $rs] + ReaderTokenService::issue($rs, $bookId, $r['file']['id'])]);
    }

    public function manifest(Request $request): Response
    {
        $bookId = (int) $request->param('id');
        $a = ReaderSessionService::authorize($bookId, $request);
        return $this->json($this->buildManifest($a, (int) $a['session']['id'], false));
    }

    public function page(Request $request): Response
    {
        return $this->image($request, 'page');
    }

    public function thumbnail(Request $request): Response
    {
        return $this->image($request, 'thumb');
    }

    private function image(Request $request, string $variant): Response
    {
        $bookId = (int) $request->param('id');
        $page = (int) $request->param('page');
        $a = ReaderSessionService::authorize($bookId, $request);
        if ($page < 1 || $page > $a['file']['pages']) {
            throw HttpException::notFound();
        }
        $rs = (int) $a['session']['id'];
        $bucket = $variant === 'thumb' ? 'reader_thumbs' : 'reader_pages';
        RateLimiter::enforce($bucket, "rs:$rs");
        RateLimiter::enforce($bucket, 'ip:' . $request->ip());

        $response = PageDeliveryService::render($a['file']['id'], $page, $variant, (string) $a['session']['reader_pseudonym']);
        if ($variant === 'page') {
            \App\Core\Database::query(
                'UPDATE reading_sessions SET pages_viewed = pages_viewed + 1, last_activity_at = :t WHERE id = :id',
                ['t' => gmdate('Y-m-d H:i:s'), 'id' => $rs]
            );
        }
        return $response;
    }

    /** Manifest: counts, version, URL templates and a token. Never paths or file names. */
    private function buildManifest(array $r, int $rs, bool $withProgress): array
    {
        $bookId = (int) $r['book']['id'];
        $first = \App\Core\Database::first("SELECT width, height FROM book_page_renders WHERE book_file_id = :f AND variant = 'page' AND page_number = 1", ['f' => $r['file']['id']]);
        $data = [
            'book' => ['id' => $bookId, 'slug' => $r['book']['slug'], 'title' => $r['book']['title']],
            'fileVersion' => $r['file']['version'],
            'pageCount' => $r['file']['pages'],
            'aspect' => $first ? round((int) $first['height'] / max(1, (int) $first['width']), 4) : 1.414,
            'pageUrl' => "/api/books/$bookId/pages/{n}",
            'thumbUrl' => "/api/books/$bookId/thumbnails/{n}",
            'token' => ['rs' => $rs] + ReaderTokenService::issue($rs, $bookId, $r['file']['id']),
            'member' => Auth::user() !== null,
        ];
        if ($withProgress && ($uid = Auth::id())) {
            $data['progress'] = ReadingProgressService::get($uid, $bookId);
        }
        return $data;
    }
}
