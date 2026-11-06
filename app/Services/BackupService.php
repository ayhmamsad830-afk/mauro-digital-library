<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use RuntimeException;

/**
 * Backups into storage/backups (outside the web root):
 *  - database: mysqldump (credentials passed via a temporary 0600 defaults
 *    file, never on the command line) → gzip, with a "-- Dump completed" check;
 *  - files: tar.gz of the irreplaceable private files (original PDFs, covers,
 *    author photos). Rendered pages are NOT included: they are regenerated
 *    from the originals.
 * Every archive gets a .sha256 sidecar. Restores are manual, deliberate
 * operations (see docs/DEPLOYMENT.md) — never automated.
 */
final class BackupService
{
    public const FILE_DIRS = ['private/original-pdf', 'private/covers', 'private/author-photos'];

    public static function dir(): string
    {
        $dir = rtrim((string) Config::get('storage.backups', BASE_PATH . '/storage/backups'), '/');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create backup directory: $dir");
        }
        return $dir;
    }

    /** @return string path of the .sql.gz */
    public static function database(?string $stamp = null): string
    {
        if (Config::get('database.driver') !== 'mysql' || (string) Config::get('database.username') === '') {
            throw new RuntimeException('No MySQL database configured (DB_USERNAME empty) — database backup skipped.');
        }
        $bin = ProcessRunner::which((string) Config::get('backup.mysqldump', 'mysqldump'));
        if ($bin === null) {
            throw new RuntimeException('mysqldump not found — use the hosting panel backup or install the MySQL client.');
        }
        $stamp ??= gmdate('Ymd-His');
        $dir = self::dir();
        $raw = "$dir/db-$stamp.sql";
        $cnf = tempnam(sys_get_temp_dir(), 'dlcnf');
        chmod($cnf, 0600);
        $q = static fn (string $v): string => '"' . addcslashes($v, "\\\"") . '"';
        $lines = ['[client]', 'user=' . $q((string) Config::get('database.username')), 'password=' . $q((string) Config::get('database.password'))];
        if ((string) Config::get('database.socket') !== '') {
            $lines[] = 'socket=' . $q((string) Config::get('database.socket'));
        } else {
            $lines[] = 'host=' . $q((string) Config::get('database.host'));
            $lines[] = 'port=' . (int) Config::get('database.port');
        }
        file_put_contents($cnf, implode("\n", $lines) . "\n");
        try {
            $res = ProcessRunner::run([$bin, '--defaults-extra-file=' . $cnf, '--single-transaction', '--routines', '--triggers', '--no-tablespaces',
                '--default-character-set=utf8mb4', '--result-file=' . $raw, (string) Config::get('database.database')], 3600);
        } finally {
            @unlink($cnf);
        }
        if ($res['exit'] !== 0 || !is_file($raw)) {
            @unlink($raw);
            throw new RuntimeException('mysqldump failed: ' . mb_substr(trim($res['stderr']), 0, 300));
        }
        $gz = self::gzip($raw);
        self::checksum($gz);
        if (!self::verify($gz)['ok']) {
            throw new RuntimeException('Database dump is incomplete.');
        }
        return $gz;
    }

    /** @return string path of the .tar.gz */
    public static function files(?string $stamp = null): string
    {
        $root = rtrim((string) Config::get('storage.root'), '/');
        $dirs = array_values(array_filter(self::FILE_DIRS, static fn (string $d): bool => is_dir("$root/$d")));
        if ($dirs === []) {
            throw new RuntimeException('Nothing to back up (no private file directories).');
        }
        $stamp ??= gmdate('Ymd-His');
        $out = self::dir() . "/files-$stamp.tar.gz";
        $tar = ProcessRunner::which('tar');
        if ($tar !== null) {
            $res = ProcessRunner::run(array_merge([$tar, '-czf', $out, '-C', $root], $dirs), 3600);
            if ($res['exit'] !== 0) {
                @unlink($out);
                throw new RuntimeException('tar failed: ' . mb_substr(trim($res['stderr']), 0, 300));
            }
        } else {
            $phar = new \PharData(substr($out, 0, -3));
            foreach ($dirs as $d) {
                $phar->buildFromDirectory($root, '#^' . preg_quote("$root/$d/", '#') . '#');
            }
            $phar->compress(\Phar::GZ);
            @unlink(substr($out, 0, -3));
        }
        @chmod($out, 0640);
        self::checksum($out);
        return $out;
    }

    /** @return array{ok:bool, detail:string} */
    public static function verify(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'detail' => 'missing'];
        }
        $sum = "$path.sha256";
        if (is_file($sum) && trim((string) strtok((string) file_get_contents($sum), ' ')) !== hash_file('sha256', $path)) {
            return ['ok' => false, 'detail' => 'checksum mismatch'];
        }
        if (str_ends_with($path, '.sql.gz')) {
            $h = gzopen($path, 'rb');
            $tail = '';
            while ($h && !gzeof($h)) {
                $tail = substr($tail . (string) gzread($h, 1 << 20), -4096);
            }
            $h && gzclose($h);
            return str_contains($tail, '-- Dump completed') ? ['ok' => true, 'detail' => 'complete SQL dump'] : ['ok' => false, 'detail' => 'dump not completed'];
        }
        if (str_ends_with($path, '.tar.gz')) {
            $tar = ProcessRunner::which('tar');
            if ($tar === null) {
                return ['ok' => true, 'detail' => 'checksum ok (tar not available to list)'];
            }
            $res = ProcessRunner::run([$tar, '-tzf', $path], 600);
            $n = $res['exit'] === 0 ? count(array_filter(explode("\n", $res['stdout']), static fn ($l) => $l !== '' && !str_ends_with($l, '/'))) : 0;
            return $res['exit'] === 0 ? ['ok' => true, 'detail' => "$n files"] : ['ok' => false, 'detail' => 'corrupt archive'];
        }
        return ['ok' => false, 'detail' => 'unknown backup type'];
    }

    /** Delete backups older than $days (and their checksums). @return list<string> removed */
    public static function prune(int $days, ?int $now = null): array
    {
        $now ??= time();
        $removed = [];
        foreach (glob(self::dir() . '/{db,files}-*.{sql.gz,tar.gz}', GLOB_BRACE) ?: [] as $f) {
            if (filemtime($f) < $now - $days * 86400) {
                @unlink($f);
                @unlink("$f.sha256");
                $removed[] = basename($f);
            }
        }
        return $removed;
    }

    private static function gzip(string $raw): string
    {
        $gz = "$raw.gz";
        $in = fopen($raw, 'rb');
        $out = gzopen($gz, 'wb6');
        while (!feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
        @unlink($raw);
        @chmod($gz, 0640);
        return $gz;
    }

    private static function checksum(string $path): void
    {
        file_put_contents("$path.sha256", hash_file('sha256', $path) . '  ' . basename($path) . "\n");
    }
}
