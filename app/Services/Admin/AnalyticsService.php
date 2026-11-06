<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;

/**
 * Reading analytics from real rows only (reading_sessions, users, reviews).
 * No fabricated numbers: empty tables give zero series.
 */
final class AnalyticsService
{
    /** @return array{days:list<array{date:string,total:int,members:int}>,totals:array,devices:list<array>,topBooks:list<array>,days_count:int} */
    public static function report(int $days = 30, ?int $now = null): array
    {
        $days = max(7, min(90, $days));
        $now ??= time();
        $startDay = gmdate('Y-m-d', $now - ($days - 1) * 86400);
        $since = $startDay . ' 00:00:00';

        $rows = Database::all(
            'SELECT SUBSTR(started_at, 1, 10) AS d, COUNT(*) AS n, SUM(CASE WHEN user_id IS NULL THEN 0 ELSE 1 END) AS m
               FROM reading_sessions WHERE started_at >= :s GROUP BY SUBSTR(started_at, 1, 10)',
            ['s' => $since]
        );
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['d']] = ['total' => (int) $r['n'], 'members' => (int) $r['m']];
        }
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = gmdate('Y-m-d', strtotime($startDay . ' 00:00:00 UTC') + $i * 86400);
            $series[] = ['date' => $d] + ($byDay[$d] ?? ['total' => 0, 'members' => 0]);
        }

        $t = Database::first(
            'SELECT COUNT(*) AS sessions, SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) AS guests,
                    COALESCE(SUM(pages_viewed), 0) AS pages, COUNT(DISTINCT user_id) AS readers
               FROM reading_sessions WHERE started_at >= :s',
            ['s' => $since]
        );
        $totals = [
            'sessions' => (int) $t['sessions'],
            'guests' => (int) $t['guests'],
            'members' => (int) $t['sessions'] - (int) $t['guests'],
            'pages' => (int) $t['pages'],
            'readers' => (int) $t['readers'],
            'new_users' => (int) Database::scalar('SELECT COUNT(*) FROM users WHERE created_at >= :s', ['s' => $since]),
            'reviews' => (int) Database::scalar('SELECT COUNT(*) FROM reviews WHERE created_at >= :s', ['s' => $since]),
        ];

        $devices = Database::all(
            'SELECT device_type AS device, COUNT(*) AS n FROM reading_sessions WHERE started_at >= :s GROUP BY device_type ORDER BY n DESC, device_type',
            ['s' => $since]
        );
        $topBooks = Database::all(
            'SELECT b.id, b.title, b.slug, COUNT(s.id) AS sessions, COALESCE(SUM(s.pages_viewed), 0) AS pages
               FROM reading_sessions s JOIN books b ON b.id = s.book_id
              WHERE s.started_at >= :s GROUP BY b.id, b.title, b.slug ORDER BY sessions DESC, b.id LIMIT 10',
            ['s' => $since]
        );
        return [
            'days' => $series,
            'days_count' => $days,
            'totals' => $totals,
            'devices' => array_map(static fn ($r) => ['device' => (string) $r['device'], 'n' => (int) $r['n']], $devices),
            'topBooks' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'slug' => $r['slug'], 'sessions' => (int) $r['sessions'], 'pages' => (int) $r['pages']], $topBooks),
        ];
    }
}
