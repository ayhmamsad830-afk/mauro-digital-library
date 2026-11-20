<?php

declare(strict_types=1);

/*
 * Copy the SQLite library into the freshly migrated MySQL DEVELOPMENT database.
 *
 *   php bin/import-sqlite-to-mysql.php --source=storage/mauro.sqlite --dry-run
 *   php bin/import-sqlite-to-mysql.php --source=storage/mauro.sqlite --confirm
 *
 * Target = the DB_* MySQL settings (DB_DATABASE / DB_USERNAME), whatever DB_DRIVER
 * says, so the site can keep running on SQLite until the switch. The source is
 * opened read-only. Refused when APP_ENV=production. Exactly one of --dry-run /
 * --confirm is required. See App\Support\SqliteToMysqlImporter for the guarantees.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Support\SqliteToMysqlImporter;

restore_error_handler();
restore_exception_handler();

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$out = static fn (string $m) => fwrite(STDOUT, $m . PHP_EOL);
$fail = static function (string $m): never {
    fwrite(STDERR, 'FAIL ' . $m . PHP_EOL);
    exit(1);
};

if (Config::get('app.env') === 'production') {
    $fail('refused when APP_ENV=production');
}
$dry = isset($opts['dry-run']);
if ($dry === isset($opts['confirm'])) {
    $fail('pass exactly one of --dry-run or --confirm');
}
$sourcePath = (string) ($opts['source'] ?? '');
if ($sourcePath === '') {
    $fail('--source=<sqlite file> is required');
}

$cfg = Config::get('database');
$cfg['driver'] = 'mysql';

try {
    $source = SqliteToMysqlImporter::openSourceReadOnly($sourcePath);
    $target = Database::connect($cfg);

    $migrations = array_map(static fn ($f) => basename($f, '.sql'), glob(BASE_PATH . '/database/migrations/*.sql') ?: []);
    sort($migrations);
    // Defaults inserted by 0008_create_settings_and_rate_limits_tables.
    $seeded = [['catalog.per_page', '24'], ['home.featured_book_id', null], ['site.contact_email', null]];

    $out(($dry ? 'DRY RUN' : 'IMPORT') . ' from ' . basename($sourcePath) . ' (read-only) to ' . $cfg['host'] . ':' . $cfg['port'] . '/' . $cfg['database'] . ' as ' . $cfg['username']);
    $copied = (new SqliteToMysqlImporter($source, $target, $out))->run($migrations, $seeded, $dry);
    $out('total rows: ' . array_sum($copied) . ' in ' . count($copied) . ' tables');
    exit(0);
} catch (Throwable $e) {
    $fail(strtok($e->getMessage(), "\n") ?: 'import failed');
}
