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
use App\Services\Reader\ReaderSessionService;

/** Member reading progress API (behind AuthMiddleware; CSRF on POST). */
final class ProgressController extends Controller
{
    public function store(Request $request): Response
    {
        $uid = (int) Auth::id();
        RateLimiter::enforce('reading_progress', "u:$uid");
        $bookId = (int) $request->input('book_id', 0);
        $page = $request->input('page');
        $version = $request->input('file_version');
        if (!is_int($page) && !(is_string($page) && ctype_digit($page))) {
            throw new HttpException(422, __('validation.invalid'));
        }
        $r = ReaderSessionService::readableBook($bookId);
        return $this->json(ReadingProgressService::save($uid, $r['book'], $r['file'], (int) $page, (int) $version));
    }

    public function show(Request $request): Response
    {
        $bookId = (int) $request->param('bookId');
        ReaderSessionService::readableBook($bookId); // same visibility policy
        $p = ReadingProgressService::get((int) Auth::id(), $bookId);
        if ($p === null) {
            throw HttpException::notFound();
        }
        return $this->json($p);
    }
}
