<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Models\ActivityLog;
use App\Repositories\MySqlLibraryRepository;
use App\Services\Member\MemberLibrary;
use App\Support\Paginator;

/**
 * Account administration (spec FR-018). Guard rails: an admin cannot change
 * their own status/role, and the last active admin can never be suspended
 * or demoted. Suspension is effective on the user's next request because
 * Auth re-reads the account from the database every time.
 */
final class UserAdminService
{
    public const ROLES = ['user', 'admin'];
    public const STATUSES = ['active', 'suspended'];

    public static function paginate(string $q, ?string $role, ?string $status, int $page, int $perPage = 25): Paginator
    {
        $where = ['1 = 1'];
        $params = [];
        if ($role !== null && in_array($role, self::ROLES, true)) {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        }
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }
        $q = trim(mb_substr($q, 0, 100));
        if ($q !== '') {
            $where[] = "(LOWER(u.name) LIKE :qn ESCAPE '!' OR LOWER(u.email) LIKE :qe ESCAPE '!')";
            $params['qn'] = $params['qe'] = '%' . MySqlLibraryRepository::escapeLike(mb_strtolower($q)) . '%';
        }
        $w = implode(' AND ', $where);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM users u WHERE $w", $params);
        $page = max(1, min($page, max(1, (int) ceil($total / $perPage))));
        $rows = Database::all(
            "SELECT u.id, u.name, u.email, u.role, u.status, u.last_login_at, u.created_at,
                    (SELECT COUNT(*) FROM reviews r WHERE r.user_id = u.id) AS reviews_count,
                    (SELECT COUNT(*) FROM reading_progress p WHERE p.user_id = u.id) AS books_count
               FROM users u WHERE $w ORDER BY u.created_at DESC, u.id DESC LIMIT :lim OFFSET :off",
            $params + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );
        return new Paginator($rows, $total, $page, $perPage);
    }

    public static function setStatus(int $userId, string $status, int $adminId, ?string $ip): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new HttpException(422, __('validation.invalid'));
        }
        $user = self::target($userId, $adminId);
        if ($user['status'] === $status) {
            return;
        }
        if ($status === 'suspended' && $user['role'] === 'admin' && self::activeAdmins() <= 1) {
            throw new HttpException(422, __('admin.users_last_admin'));
        }
        Database::transaction(static function () use ($user, $status, $adminId, $ip): void {
            Database::query('UPDATE users SET status = :s, updated_at = :t WHERE id = :id', ['s' => $status, 't' => gmdate('Y-m-d H:i:s'), 'id' => $user['id']]);
            // Ratings count only active reviewers: refresh every book this user reviewed.
            foreach (Database::query('SELECT book_id FROM reviews WHERE user_id = :u', ['u' => $user['id']])->fetchAll(\PDO::FETCH_COLUMN) as $bookId) {
                MemberLibrary::recomputeRating((int) $bookId);
            }
            ActivityLog::record($adminId, $status === 'suspended' ? 'user.suspended' : 'user.activated', 'user', (int) $user['id'], $user['email'], $ip);
        });
    }

    public static function setRole(int $userId, string $role, int $adminId, ?string $ip): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new HttpException(422, __('validation.invalid'));
        }
        $user = self::target($userId, $adminId);
        if ($user['role'] === $role) {
            return;
        }
        if ($role === 'user' && $user['status'] === 'active' && self::activeAdmins() <= 1) {
            throw new HttpException(422, __('admin.users_last_admin'));
        }
        Database::transaction(static function () use ($user, $role, $adminId, $ip): void {
            Database::query('UPDATE users SET role = :r, updated_at = :t WHERE id = :id', ['r' => $role, 't' => gmdate('Y-m-d H:i:s'), 'id' => $user['id']]);
            ActivityLog::record($adminId, $role === 'admin' ? 'user.promoted' : 'user.demoted', 'user', (int) $user['id'], $user['email'], $ip);
        });
    }

    private static function target(int $userId, int $adminId): array
    {
        $user = Database::first('SELECT id, email, role, status FROM users WHERE id = :id', ['id' => $userId]);
        if (!$user) {
            throw HttpException::notFound();
        }
        if ((int) $user['id'] === $adminId) {
            throw new HttpException(422, __('admin.users_not_self'));
        }
        return $user;
    }

    private static function activeAdmins(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
    }
}
