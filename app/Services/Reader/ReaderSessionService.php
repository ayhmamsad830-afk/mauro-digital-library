<?php

declare(strict_types=1);

namespace App\Services\Reader;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Repositories\LibraryRepositoryFactory;
use App\Services\Auth;
use App\Services\BookAccessPolicy;

/**
 * Reading sessions: one per (browser session, book, file version). Backs the
 * signed page tokens, the per-reader watermark pseudonym, analytics
 * (reading_sessions) and the "most read" counter (books.reads_count).
 */
final class ReaderSessionService
{
    /**
     * Resolve a book the viewer may read, with a fully rendered current version.
     * Guests asking for members-only, unpublished or unknown books get 404.
     *
     * @return array{book: array, file: array}
     */
    public static function readableBook(int $bookId): array
    {
        $book = LibraryRepositoryFactory::make()->findBookById($bookId);
        if ($book === null || !BookAccessPolicy::canView($book)) {
            throw HttpException::notFound();
        }
        $file = $book['current_file_id'] ? Database::first(
            "SELECT id, version_number, page_count FROM book_files WHERE id = :f AND validation_status = 'valid' AND render_status = 'ready'",
            ['f' => $book['current_file_id']]
        ) : null;
        if (!$file || (int) $file['page_count'] < 1) {
            throw new HttpException(503, __('reader.not_ready'));
        }
        return ['book' => $book, 'file' => ['id' => (int) $file['id'], 'version' => (int) $file['version_number'], 'pages' => (int) $file['page_count']]];
    }

    /**
     * Start (or reuse) the reading session for this browser + book + version.
     * @return array{id:int, pseudonym:string, created:bool}
     */
    public static function start(array $book, array $file, Request $request): array
    {
        $reuse = (array) Session::get('_reader_sessions', []);
        $key = $book['id'] . ':' . $file['id'];
        $now = gmdate('Y-m-d H:i:s');
        if (isset($reuse[$key])) {
            $row = Database::first(
                'SELECT id, reader_pseudonym FROM reading_sessions WHERE id = :id AND book_id = :b AND book_file_id = :f AND ended_at IS NULL AND expires_at > :n',
                ['id' => (int) $reuse[$key], 'b' => $book['id'], 'f' => $file['id'], 'n' => $now]
            );
            if ($row) {
                return ['id' => (int) $row['id'], 'pseudonym' => $row['reader_pseudonym'], 'created' => false];
            }
        }

        $pseudonym = 'R-' . strtoupper(bin2hex(random_bytes(4)));
        $id = Database::transaction(static function () use ($book, $file, $request, $pseudonym, $now): int {
            Database::query(
                'INSERT INTO reading_sessions (user_id, book_id, book_file_id, session_token_hash, reader_pseudonym, ip_hash, device_type,
                                               pages_viewed, started_at, last_activity_at, expires_at)
                 VALUES (:u, :b, :f, :h, :p, :ip, :d, 0, :s, :l, :e)',
                [
                    'u' => Auth::id(), 'b' => $book['id'], 'f' => $file['id'],
                    'h' => hash('sha256', random_bytes(32)), 'p' => substr($pseudonym, 0, 10),
                    'ip' => hash_hmac('sha256', $request->ip(), app_key()),
                    'd' => self::deviceType($request->userAgent()), 's' => $now, 'l' => $now,
                    'e' => gmdate('Y-m-d H:i:s', time() + (int) Config::get('rendering.reader_session_ttl', 7200)),
                ]
            );
            $id = Database::lastInsertId();
            // "Most read" counts real reading sessions, not page loads (spec §9.1 Analytics).
            Database::query('UPDATE books SET reads_count = reads_count + 1 WHERE id = :b', ['b' => $book['id']]);
            return $id;
        });
        $reuse[$key] = $id;
        Session::put('_reader_sessions', array_slice($reuse, -20, null, true));
        return ['id' => $id, 'pseudonym' => substr($pseudonym, 0, 10), 'created' => true];
    }

    /**
     * Validate a page/manifest request. Throws 401 (expired), 403 (invalid),
     * 404 (book not readable), 409 (version replaced) as appropriate.
     *
     * @return array{session: array, book: array, file: array}
     */
    public static function authorize(int $bookId, Request $request): array
    {
        $rs = self::intParam($request, 'rs');
        $exp = self::intParam($request, 'exp');
        $sig = (string) $request->query('sig', '');
        if ($rs < 1 || $exp < 1 || !preg_match('/^[A-Za-z0-9_-]{43}$/', $sig)) {
            throw new HttpException(403, __('reader.token_invalid'));
        }

        // Policy first: a guest must never learn anything about a restricted book.
        $readable = self::readableBook($bookId);

        $row = Database::first(
            'SELECT id, book_id, book_file_id, reader_pseudonym, expires_at, ended_at FROM reading_sessions WHERE id = :id',
            ['id' => $rs]
        );
        if (!$row || (int) $row['book_id'] !== $bookId) {
            throw new HttpException(403, __('reader.token_invalid'));
        }
        $check = ReaderTokenService::check($rs, $bookId, (int) $row['book_file_id'], $exp, $sig);
        if ($check === 'invalid') {
            throw new HttpException(403, __('reader.token_invalid'));
        }
        if ($check === 'expired' || $row['ended_at'] !== null || (string) $row['expires_at'] <= gmdate('Y-m-d H:i:s')) {
            throw new HttpException(401, __('reader.token_expired'));
        }
        if ((int) $row['book_file_id'] !== $readable['file']['id']) {
            throw new HttpException(409, __('reader.version_changed'));
        }
        return ['session' => $row] + $readable;
    }

    private static function intParam(Request $request, string $key): int
    {
        $v = $request->query($key);
        return is_string($v) && ctype_digit($v) && strlen($v) <= 12 ? (int) $v : 0;
    }

    public static function deviceType(string $ua): string
    {
        $ua = strtolower($ua);
        return match (true) {
            $ua === '' => 'unknown',
            str_contains($ua, 'ipad') || str_contains($ua, 'tablet') => 'tablet',
            str_contains($ua, 'mobi') || str_contains($ua, 'android') => 'mobile',
            default => 'desktop',
        };
    }
}
