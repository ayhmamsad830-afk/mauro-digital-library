<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\LibraryRepositoryFactory;
use App\Services\Auth;
use App\Services\BookAccessPolicy;
use App\Services\Member\MemberLibrary;

/**
 * Member actions (all behind AuthMiddleware + global CSRF). Each endpoint
 * answers JSON to fetch() callers and redirects back for plain HTML forms,
 * so everything works without JavaScript.
 */
final class MemberController extends Controller
{
    public function toggleFavorite(Request $request): Response
    {
        $bookId = (int) $request->param('bookId');
        $state = MemberLibrary::toggleFavorite($this->uid(), $bookId);
        return $this->respond($request, ['favorite' => $state], $state ? __('member.favorite_added') : __('member.favorite_removed'));
    }

    public function addBookmark(Request $request): Response
    {
        $id = MemberLibrary::addBookmark(
            $this->uid(),
            (int) $request->input('book_id', 0),
            (int) $request->input('page', 0),
            self::optional($request->input('title')),
            self::optional($request->input('note'))
        );
        return $this->respond($request, ['id' => $id], __('member.bookmark_added'));
    }

    public function deleteBookmark(Request $request): Response
    {
        MemberLibrary::deleteBookmark($this->uid(), (int) $request->param('id'));
        return $this->respond($request, ['deleted' => true], __('member.bookmark_deleted'));
    }

    public function saveReview(Request $request): Response
    {
        $book = $this->bookFromSlug($request);
        try {
            MemberLibrary::saveReview($this->uid(), (int) $book['id'], $request->input('rating'), $request->input('comment'));
        } catch (HttpException $e) {
            if ($request->wantsJson() || $e->status() === 429) {
                throw $e;
            }
            Session::flash('review_error', $e->getMessage());
            Session::flash('old', ['rating' => $request->input('rating'), 'comment' => $request->input('comment')]);
            return $this->redirect(route('books.show', ['slug' => $book['slug']]) . '#reviews', 303);
        }
        return $this->respond($request, ['saved' => true], __('member.review_saved'), route('books.show', ['slug' => $book['slug']]) . '#reviews');
    }

    public function deleteReview(Request $request): Response
    {
        $book = $this->bookFromSlug($request);
        MemberLibrary::deleteReview($this->uid(), (int) $book['id']);
        return $this->respond($request, ['deleted' => true], __('member.review_deleted'), route('books.show', ['slug' => $book['slug']]) . '#reviews');
    }

    public function importProgress(Request $request): Response
    {
        $items = $request->input('items', []);
        $n = MemberLibrary::importLocalProgress($this->uid(), is_array($items) ? $items : []);
        return $this->json(['imported' => $n]);
    }

    private function uid(): int
    {
        return (int) Auth::id();
    }

    private function bookFromSlug(Request $request): array
    {
        $book = LibraryRepositoryFactory::make()->findBookBySlug((string) $request->param('slug'));
        if ($book === null || !BookAccessPolicy::canView($book)) {
            throw HttpException::notFound();
        }
        return $book;
    }

    private function respond(Request $request, array $json, string $message, ?string $fallback = null): Response
    {
        if ($request->wantsJson() || $request->isJson()) {
            return $this->json($json + ['message' => $message]);
        }
        Session::flash('notice', $message);
        $back = safe_redirect_path((string) $request->input('redirect', ''), $fallback ?? route('account'));
        return $this->redirect($back, 303);
    }

    private static function optional(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
