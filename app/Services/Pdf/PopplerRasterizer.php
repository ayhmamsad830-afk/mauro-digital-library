<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Core\Config;
use App\Services\ProcessRunner;
use RuntimeException;

/**
 * Poppler pdftoppm rasteriser. Width is fixed (-scale-to-x) and height keeps
 * the page aspect (-scale-to-y -1), which bounds memory for huge page sizes.
 * Runs without a shell, with a timeout, in a private temp directory.
 */
final class PopplerRasterizer implements Rasterizer
{
    public function name(): string
    {
        return 'poppler';
    }

    public function rasterize(string $pdfPath, int $from, int $to, string $outDir, int $width): array
    {
        $bin = ProcessRunner::which((string) Config::get('rendering.binaries.pdftoppm', 'pdftoppm'));
        if ($bin === null) {
            throw new RuntimeException('pdftoppm is not available.');
        }
        $prefix = rtrim($outDir, '/') . '/p';
        $res = ProcessRunner::run([
            $bin, '-png', '-f', (string) $from, '-l', (string) $to,
            '-scale-to-x', (string) $width, '-scale-to-y', '-1',
            '--', $pdfPath, $prefix,
        ], max(30, (int) Config::get('rendering.timeout', 120)));
        if ($res['timed_out']) {
            throw new RuntimeException('Rendering timed out.');
        }
        if ($res['exit'] !== 0) {
            throw new RuntimeException('pdftoppm failed (exit ' . $res['exit'] . ').');
        }

        // pdftoppm names files p-1.png / p-01.png / p-001.png depending on page count.
        $out = [];
        foreach (glob($prefix . '-*.png') ?: [] as $file) {
            if (preg_match('/-(\d+)\.png$/', $file, $m)) {
                $out[(int) $m[1]] = $file;
            }
        }
        ksort($out);
        for ($p = $from; $p <= $to; $p++) {
            if (!isset($out[$p])) {
                throw new RuntimeException("Page $p was not produced.");
            }
        }
        return array_intersect_key($out, array_flip(range($from, $to)));
    }
}
