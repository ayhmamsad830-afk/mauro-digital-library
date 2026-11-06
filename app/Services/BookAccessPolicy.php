<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Session;

/**
 * Single source of truth for "may this viewer see / read this book?".
 *
 *  - public  → everyone (guests included)
 *  - members → signed-in, active accounts only (Google sign-in, Phase 5)
 *
 * Repositories apply the same rule in SQL; controllers that serve the book
 * itself (details, reader, page images) ALSO call canView() as a second,
 * independent check, so a future repository bug cannot leak a restricted book.
 * Guests receive 404 (not 403) so restricted titles cannot be discovered.
 */
final class BookAccessPolicy
{
    public static function viewerIsMember(): bool
    {
        // Avoid a DB query for guests: no session user means no member.
        return Session::has('user_id') && Auth::user() !== null;
    }

    /** @param array{visibility?: string} $book a book already known to be published/visible by status */
    public static function canView(array $book, ?bool $isMember = null): bool
    {
        $visibility = $book['visibility'] ?? 'public';
        if ($visibility === 'public') {
            return true;
        }
        return $visibility === 'members' && ($isMember ?? self::viewerIsMember());
    }
}
