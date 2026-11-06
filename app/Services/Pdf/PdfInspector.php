<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Core\Config;
use App\Services\ProcessRunner;
use RuntimeException;

/** Reads PDF metadata with Poppler's pdfinfo (no shell; hard timeout). */
final class PdfInspector
{
    public const ENCRYPTED = 2;

    /**
     * @return array{pages:int, encrypted:bool, width_pt:float|null, height_pt:float|null}
     * @throws RuntimeException when the file is not a readable PDF
     */
    public static function inspect(string $absPath): array
    {
        $bin = ProcessRunner::which((string) Config::get('rendering.binaries.pdfinfo', 'pdfinfo'));
        if ($bin === null) {
            throw new RuntimeException('pdfinfo is not available on this server.');
        }
        $res = ProcessRunner::run([$bin, '--', $absPath], 30);
        if (!$res['timed_out'] && $res['exit'] !== 0 && stripos($res['stderr'], 'password') !== false) {
            throw new RuntimeException('The PDF is password-protected.', self::ENCRYPTED);
        }
        if ($res['timed_out'] || $res['exit'] !== 0) {
            throw new RuntimeException('pdfinfo could not read the file.');
        }
        $out = $res['stdout'];
        if (!preg_match('/^Pages:\s+(\d+)\s*$/m', $out, $m)) {
            throw new RuntimeException('Page count not found.');
        }
        $w = $h = null;
        if (preg_match('/^Page size:\s+([\d.]+) x ([\d.]+) pts/m', $out, $s)) {
            [$w, $h] = [(float) $s[1], (float) $s[2]];
        }
        return [
            'pages' => (int) $m[1],
            'encrypted' => (bool) preg_match('/^Encrypted:\s+yes/m', $out),
            'width_pt' => $w,
            'height_pt' => $h,
        ];
    }
}
