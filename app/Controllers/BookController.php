<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class BookController extends Controller
{
    public function show(Request $request): Response
    {
        $library = $this->library();
        $book = $library->findBookBySlug((string) $request->param('slug'));
        if ($book === null || !\App\Services\BookAccessPolicy::canView($book)) {
            // Unpublished, archived, members-only (for guests) and unknown books
            // are indistinguishable: always 404 (TC-006).
            throw HttpException::notFound();
        }

        return $this->view('library/book', [
            'pageTitle' => $book['title'],
            'metaDescription' => mb_substr((string) $book['description'], 0, 160),
            'book' => $book,
            'related' => $library->relatedBooks($book, 6),
            'reviews' => $library->bookReviews((int) $book['id'], 5),
            'isMember' => ($uid = \App\Services\Auth::id()) !== null,
            'isFavorite' => $uid !== null && \App\Services\Member\MemberLibrary::isFavorite($uid, (int) $book['id']),
            'myReview' => $uid !== null ? \App\Services\Member\MemberLibrary::myReview($uid, (int) $book['id']) : null,
            'reviewError' => \App\Core\Session::getFlash('review_error'),
        ]);
    }
}
