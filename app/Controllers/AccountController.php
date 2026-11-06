<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Demo\DemoData;
use App\Services\Auth;
use App\Services\Member\MemberLibrary;

/**
 * /account (behind AuthMiddleware): profile, continue reading, history,
 * favorites, bookmarks, settings and sign-out. /demo/account is the
 * fictional design preview (demo mode only).
 */
final class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render('overview');
    }

    public function history(Request $request): Response
    {
        return $this->render('history');
    }

    public function favorites(Request $request): Response
    {
        return $this->render('favorites');
    }

    public function bookmarks(Request $request): Response
    {
        return $this->render('bookmarks');
    }

    private function render(string $section): Response
    {
        $user = Auth::user();
        $uid = (int) $user['id'];
        $history = MemberLibrary::history($uid, $section === 'history' ? 50 : 4);
        $favorites = MemberLibrary::favorites($uid);
        $bookmarks = MemberLibrary::bookmarks($uid);
        return $this->view('account/member', [
            'pageTitle' => __('account.title'),
            'noindex' => true,
            'section' => $section,
            'user' => $user + ['created_at' => (string) \App\Core\Database::scalar('SELECT created_at FROM users WHERE id = :id', ['id' => $uid])],
            'history' => $history,
            'favorites' => $section === 'overview' ? array_slice($favorites, 0, 4) : $favorites,
            'favoritesCount' => count($favorites),
            'bookmarks' => $section === 'overview' ? array_slice($bookmarks, 0, 4) : $bookmarks,
            'bookmarksCount' => count($bookmarks),
            'scripts' => ['account.js'],
        ]);
    }

    /** Fictional design preview (demo mode only). */
    public function preview(Request $request): Response
    {
        $this->requireDemo();
        $library = $this->library();
        $books = $library->latestBooks(6);
        $history = array_map(static fn (array $b, int $i) => ['book' => $b, 'page' => [42, 12, 120, 5][$i] ?? 1, 'percent' => [68, 9, 47, 3][$i] ?? 0], array_slice($books, 0, 4), [0, 1, 2, 3]);

        return $this->view('account/index', [
            'pageTitle' => __('account.title'),
            'noindex' => true,
            'user' => DemoData::user(),
            'history' => $history,
            'favorites' => array_slice($library->popularBooks(6), 0, 4),
            'bookmarks' => [
                ['book' => $books[0], 'page' => 42, 'title' => __('demo.bookmark_title'), 'note' => __('demo.bookmark_note')],
                ['book' => $books[2], 'page' => 17, 'title' => null, 'note' => null],
            ],
        ]);
    }
}
