<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Models\ActivityLog;
use App\Services\Member\MemberLibrary;
use App\Support\Paginator;

/** Review moderation (spec FR-016/FR-018): hide or re-publish, rating recomputed. */
final class ReviewAdminService
{
    public static function paginate(?string $status, int $page, int $perPage = 25): Paginator
    {
        $where = 'b.deleted_at IS NULL';
        $params = [];
        if (in_array($status, ['published', 'hidden'], true)) {
            $where .= ' AND r.status = :st';
            $params['st'] = $status;
        }
        $total = (int) Database::scalar("SELECT COUNT(*) FROM reviews r JOIN books b ON b.id = r.book_id WHERE $where", $params);
        $page = max(1, min($page, max(1, (int) ceil($total / $perPage))));
        $rows = Database::all(
            "SELECT r.id, r.rating, r.comment, r.status, r.created_at, r.updated_at, r.moderated_at,
                    b.id AS book_id, b.title AS book_title, b.slug AS book_slug, u.name AS user_name, u.email AS user_email, u.status AS user_status
               FROM reviews r JOIN books b ON b.id = r.book_id JOIN users u ON u.id = r.user_id
              WHERE $where ORDER BY r.created_at DESC, r.id DESC LIMIT :lim OFFSET :off",
            $params + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );
        return new Paginator($rows, $total, $page, $perPage);
    }

    public static function setStatus(int $reviewId, string $status, int $adminId, ?string $ip): void
    {
        if (!in_array($status, ['published', 'hidden'], true)) {
            throw new HttpException(422, __('validation.invalid'));
        }
        $r = Database::first('SELECT r.id, r.book_id, r.status, b.title FROM reviews r JOIN books b ON b.id = r.book_id WHERE r.id = :id', ['id' => $reviewId]);
        if (!$r) {
            throw HttpException::notFound();
        }
        if ($r['status'] === $status) {
            return;
        }
        Database::transaction(static function () use ($r, $status, $adminId, $ip): void {
            Database::query('UPDATE reviews SET status = :s, moderated_by = :a, moderated_at = :t WHERE id = :id',
                ['s' => $status, 'a' => $adminId, 't' => gmdate('Y-m-d H:i:s'), 'id' => $r['id']]);
            MemberLibrary::recomputeRating((int) $r['book_id']);
            ActivityLog::record($adminId, $status === 'hidden' ? 'review.hidden' : 'review.published', 'review', (int) $r['id'], $r['title'], $ip);
        });
    }
}
