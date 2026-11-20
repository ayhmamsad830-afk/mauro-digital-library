<?php

declare(strict_types=1);

/*
 * Dependency-free test runner:   php tests/run.php [filter]
 * Discovers tests/{Unit,Feature,Security}/*Test.php; runs public test* methods.
 */

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/TestCase.php';

// Test classes (Tests\*) autoload from tests/ regardless of how Composer was dumped (--no-dev).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Tests\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

restore_error_handler();
restore_exception_handler();
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false; // respect the @ operator
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Never the developer's real database (storage/mauro.sqlite, or mauro_library_dev once
// .env has DB_DRIVER=mysql): the default in-process connection is an empty in-memory
// SQLite. Tests that need a database get one from Tests\Support\TestDatabase; HTTP test
// servers are isolated in Tests\Feature\HttpServer. The DB_* names stay as configured so
// TestDatabaseConfig can still refuse a test database equal to the development one.
App\Core\Config::set('database.driver', 'sqlite');
App\Core\Config::set('database.sqlite_path', '');
App\Core\Database::setConnection(null);

$filter = $argv[1] ?? '';
$files = array_merge(...array_map(
    static fn (string $dir) => glob(__DIR__ . "/$dir/*Test.php") ?: [],
    ['Unit', 'Feature', 'Security', 'Integration']
));

$passed = $failed = $skipped = $assertions = 0;
$failures = [];
$skips = [];
$start = microtime(true);

foreach ($files as $file) {
    require_once $file;
    $class = 'Tests\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $name = basename(dirname($file)) . '\\' . basename($file, '.php') . '::' . $method;
        if ($filter !== '' && !str_contains($name, $filter)) {
            continue;
        }
        $test = new $class();
        // Controllers may call set_time_limit() (PDF upload: 300 s); that limit must not
        // carry over to the rest of the suite, which runs far longer on MySQL.
        set_time_limit(0);
        try {
            $test->setUp();
            $test->$method();
            $passed++;
            echo '.';
        } catch (Tests\SkippedTest $e) {
            $skipped++;
            $skips[$e->getMessage()][] = $name;
            echo 'S';
        } catch (Throwable $e) {
            $failed++;
            $failures[] = [$name, $e];
            echo 'F';
        } finally {
            try {
                $test->tearDown();
            } catch (Throwable) {
            }
            $assertions += $test->assertions;
        }
    }
}

echo PHP_EOL . PHP_EOL;
foreach ($failures as $i => [$name, $e]) {
    printf("%d) %s\n   %s\n   at %s:%d\n\n", $i + 1, $name, str_replace("\n", "\n   ", $e->getMessage()), $e->getFile(), $e->getLine());
}
foreach ($skips as $reason => $names) {
    printf("SKIPPED (%d): %s\n   %s\n\n", count($names), $reason, implode("\n   ", $names));
}
printf("%s — %d passed, %d failed, %d skipped, %d assertions (%.2fs)\n",
    $failed ? 'FAILURES' : ($skipped ? 'OK (with skips)' : 'OK'), $passed, $failed, $skipped, $assertions, microtime(true) - $start);
exit($failed ? 1 : 0);
