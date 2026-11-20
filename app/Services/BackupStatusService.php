<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Read-only view of storage/backups for the admin "Backups" screen.
 * Lists what exists and verifies integrity; it never creates, restores, deletes
 * or serves a backup (backups are run by `bin/console backup:run` from cron/CLI,
 * and files stay outside the web root).
 */
final class BackupStatusService
{
    /** Files bigger than this are listed but not hashed on page load. */
    public const MAX_VERIFY_BYTES = 200 * 1048576;

    /** @return list<array{name:string,type:string,size:int,modified:int,checksum:string,integrity:string,detail:string}> newest first */
    public static function list(): array
    {
        $dir = BackupService::dir();
        $out = [];
        foreach (glob($dir . '/*') ?: [] as $path) {
            $name = basename($path);
            if (!is_file($path) || str_ends_with($name, '.sha256') || str_starts_with($name, '.')) {
                continue;
            }
            $type = self::type($name);
            if ($type === null) {
                continue;
            }
            $size = (int) filesize($path);
            [$checksum, $integrity, $detail] = self::verify($path, $type, $size);
            $out[] = ['name' => $name, 'type' => $type, 'size' => $size, 'modified' => (int) filemtime($path),
                      'checksum' => $checksum, 'integrity' => $integrity, 'detail' => $detail];
        }
        usort($out, static fn (array $a, array $b) => $b['modified'] <=> $a['modified']);
        return $out;
    }

    /** Latest verified application backup (db-*.sql.gz) or null. */
    public static function latestDatabaseBackup(array $list): ?array
    {
        foreach ($list as $b) {
            if ($b['type'] === 'database' && $b['integrity'] === 'ok') {
                return $b;
            }
        }
        return null;
    }

    private static function type(string $name): ?string
    {
        return match (true) {
            (bool) preg_match('/^db-\d{8}-\d{6}\.sql\.gz$/', $name) => 'database',
            (bool) preg_match('/^files-\d{8}-\d{6}\.tar\.gz$/', $name) => 'files',
            (bool) preg_match('/\.sql$/', $name) => 'manual_sql',
            (bool) preg_match('/\.sqlite$/', $name) => 'sqlite',
            default => null,
        };
    }

    /** @return array{0:string,1:string,2:string} [checksum: match|mismatch|missing|skipped, integrity: ok|invalid|unchecked, detail] */
    private static function verify(string $path, string $type, int $size): array
    {
        if ($size > self::MAX_VERIFY_BYTES) {
            return ['skipped', 'unchecked', 'size'];
        }
        $sumFile = $path . '.sha256';
        $checksum = 'missing';
        if (is_file($sumFile)) {
            $expected = strtolower(trim((string) strtok((string) file_get_contents($sumFile), " \t\r\n")));
            $checksum = hash_equals($expected, hash_file('sha256', $path)) ? 'match' : 'mismatch';
        }
        if ($checksum === 'mismatch') {
            return [$checksum, 'invalid', 'checksum'];
        }
        if (in_array($type, ['database', 'files'], true)) {
            $v = BackupService::verify($path);
            return [$checksum, $v['ok'] ? 'ok' : 'invalid', $v['detail']];
        }
        if ($type === 'manual_sql') {
            $tail = '';
            $h = fopen($path, 'rb');
            if ($h) {
                fseek($h, max(0, $size - 4096));
                $tail = (string) stream_get_contents($h);
                fclose($h);
            }
            return [$checksum, str_contains($tail, '-- Dump completed') ? 'ok' : 'invalid', 'sql'];
        }
        // SQLite copy: header check only (opening it could create journals next to a backup).
        $h = fopen($path, 'rb');
        $head = $h ? (string) fread($h, 16) : '';
        $h && fclose($h);
        return [$checksum, $head === "SQLite format 3\0" ? 'ok' : 'invalid', 'sqlite'];
    }
}
