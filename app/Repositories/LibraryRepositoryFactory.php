<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\LibraryRepository;
use App\Core\Config;
use App\Demo\DemoLibraryRepository;

/**
 * Single switch point for the catalogue data source:
 *
 *  1. APP_DEMO_UI=true (never in production)  → DemoLibraryRepository (fictional, labelled)
 *  2. database configured                      → MySqlLibraryRepository (real data)
 *  3. database not configured                  → UnavailableLibraryRepository (honest empty states)
 *
 * If the database is configured but unreachable, the PDO error surfaces and
 * ErrorHandler answers 503 — the site never silently falls back to demo data.
 */
final class LibraryRepositoryFactory
{
    private static ?LibraryRepository $instance = null;

    public static function make(): LibraryRepository
    {
        if (self::$instance === null) {
            self::$instance = match (true) {
                (bool) Config::get('ui.demo', false) => new DemoLibraryRepository(),
                self::databaseConfigured() => new MySqlLibraryRepository(),
                default => new UnavailableLibraryRepository(),
            };
        }
        return self::$instance;
    }

    public static function databaseConfigured(): bool
    {
        if (Config::get('database.driver') === 'sqlite') {
            return (string) Config::get('database.sqlite_path', '') !== '';
        }
        return (string) Config::get('database.username', '') !== '' && (string) Config::get('database.database', '') !== '';
    }

    /** Tests may inject a repository (null resets to automatic selection). */
    public static function set(?LibraryRepository $repository): void
    {
        self::$instance = $repository;
    }
}
