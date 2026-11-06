<?php

declare(strict_types=1);

/*
 * Front controller — the ONLY PHP entry point exposed to the web.
 * Everything else (app, config, storage, .env) lives outside public/.
 */

use App\Core\Kernel;
use App\Core\Request;

require dirname(__DIR__) . '/app/bootstrap.php';

// When running under `php -S`, let the built-in server serve real static files
// from public/ (CSS/JS/images). Apache/Nginx do this via rewrite rules.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = realpath(__DIR__ . $path);
    if ($file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
        && basename($file) !== 'index.php' && !str_starts_with(basename($file), '.')) {
        return false;
    }
}

(new Kernel())->handle(Request::fromGlobals())->send();
