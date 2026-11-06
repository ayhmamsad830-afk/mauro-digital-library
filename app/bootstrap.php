<?php

declare(strict_types=1);

/*
 * Application bootstrap shared by the web front controller, the console and
 * the test runner. Loads the autoloader, environment, configuration and the
 * error handler. Nothing here produces output.
 */

use App\Core\Config;
use App\Core\Env;
use App\Core\ErrorHandler;

define('BASE_PATH', dirname(__DIR__));

if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
} else {
    // Fallback PSR-4 autoloader so the app runs before `composer install`
    // (e.g. on hosts without Composer). Composer's autoloader is preferred.
    spl_autoload_register(static function (string $class): void {
        $prefixes = ['App\\' => BASE_PATH . '/app/', 'Tests\\' => BASE_PATH . '/tests/'];
        foreach ($prefixes as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    });
    require_once BASE_PATH . '/app/Support/helpers.php';
}

Env::load(BASE_PATH . '/.env');
Config::loadDirectory(BASE_PATH . '/config');

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

ErrorHandler::register();
