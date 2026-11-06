<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** admin_activity_logs — who did what, when, on which entity (spec FR-019). */
final class ActivityLog
{
    public static function record(int $adminId, string $action, string $entityType, ?int $entityId, ?string $description, ?string $ip): void
    {
        Database::query(
            'INSERT INTO admin_activity_logs (admin_id, action, entity_type, entity_id, description, ip_address, created_at)
             VALUES (:admin, :action, :type, :eid, :descr, :ip, :at)',
            [
                'admin' => $adminId, 'action' => substr($action, 0, 100), 'type' => substr($entityType, 0, 50),
                'eid' => $entityId, 'descr' => $description !== null ? mb_substr($description, 0, 1000) : null,
                'ip' => $ip !== null ? substr($ip, 0, 45) : null, 'at' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 10): array
    {
        return Database::all(
            'SELECT l.action, l.entity_type, l.entity_id, l.description, l.created_at, u.name AS admin_name
               FROM admin_activity_logs l JOIN users u ON u.id = l.admin_id
              ORDER BY l.created_at DESC, l.id DESC LIMIT :lim',
            ['lim' => $limit]
        );
    }

    /** Paginated log with optional entity-type and admin filters. */
    public static function paginate(?string $entityType, ?int $adminId, int $page, int $perPage = 30): \App\Support\Paginator
    {
        $where = ['1 = 1'];
        $params = [];
        if ($entityType !== null && preg_match('/^[a-z_]{1,50}$/', $entityType)) {
            $where[] = 'l.entity_type = :t';
            $params['t'] = $entityType;
        }
        if ($adminId !== null && $adminId > 0) {
            $where[] = 'l.admin_id = :a';
            $params['a'] = $adminId;
        }
        $w = implode(' AND ', $where);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM admin_activity_logs l WHERE $w", $params);
        $page = max(1, min($page, max(1, (int) ceil($total / $perPage))));
        $rows = Database::all(
            "SELECT l.id, l.action, l.entity_type, l.entity_id, l.description, l.ip_address, l.created_at, u.name AS admin_name
               FROM admin_activity_logs l JOIN users u ON u.id = l.admin_id
              WHERE $w ORDER BY l.created_at DESC, l.id DESC LIMIT :lim OFFSET :off",
            $params + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );
        return new \App\Support\Paginator($rows, $total, $page, $perPage);
    }

    /** @return list<string> */
    public static function entityTypes(): array
    {
        return array_map('strval', Database::query('SELECT DISTINCT entity_type FROM admin_activity_logs ORDER BY entity_type')->fetchAll(\PDO::FETCH_COLUMN));
    }
}
