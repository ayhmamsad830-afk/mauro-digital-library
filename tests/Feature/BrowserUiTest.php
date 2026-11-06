<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ProcessRunner;
use Tests\TestCase;

require_once __DIR__ . '/HttpServer.php';

/**
 * Runs tests/browser/ui-check.js (Playwright/Chromium): every page at 375/768/1280 px
 * in AR and EN — no horizontal scroll, no JS errors — plus drawer, theme
 * persistence, language switch and reader interactions. Skipped (not failed)
 * when Node/Playwright are not installed, e.g. on shared hosting.
 */
final class BrowserUiTest extends TestCase
{
    public function testResponsiveLayoutsAndInteractions(): void
    {
        $node = ProcessRunner::which('node');
        $hasPlaywright = $node && ProcessRunner::run([$node, '-e', "require.resolve('playwright')"], 15)['exit'] === 0;
        if (!$hasPlaywright) {
            $this->markSkipped('node/playwright not available');
        }
        $base = HttpServer::withEnv(['APP_ENV' => 'local', 'APP_DEMO_UI' => 'true'], static fn () => HttpServer::base());
        $res = ProcessRunner::run([$node, BASE_PATH . '/tests/browser/ui-check.js', $base], 400);
        $this->assertSame(0, $res['exit'], "Browser checks failed:\n" . $res['stdout'] . $res['stderr']);
        echo "\n  [browser] " . trim(strtok($res['stdout'], "\n")) . "\n";
    }
}
