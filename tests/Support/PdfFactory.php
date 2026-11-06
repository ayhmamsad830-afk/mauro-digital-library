<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\ProcessRunner;

/** Generates small but real PDFs (and broken ones) for pipeline tests. */
final class PdfFactory
{
    /** Valid N-page A4 PDF with text and filled shapes on every page. */
    public static function make(int $pages, string $label = 'MAURO TEST'): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $font = 3 + 2 * $pages;
        for ($i = 0; $i < $pages; $i++) {
            $pageObj = 3 + 2 * $i;
            $contentObj = $pageObj + 1;
            $kids[] = "$pageObj 0 R";
            $n = $i + 1;
            $stream = "0.85 0.82 0.75 rg 50 700 495 60 re f\n"
                . "0 0 0 rg BT /F1 36 Tf 72 720 Td ($label) Tj ET\n"
                . "BT /F1 120 Tf 200 380 Td ($n) Tj ET\n"
                . "0.2 0.4 0.6 rg 72 100 450 40 re f\n";
            $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents $contentObj 0 R /Resources << /Font << /F1 $font 0 R >> >> >>";
            $objects[$contentObj] = '<< /Length ' . strlen($stream) . " >>\nstream\n$stream\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . "] /Count $pages >>";
        $objects[$font] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "$num 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $size = max(array_keys($objects)) + 1;
        $out .= "xref\n0 $size\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        return $out . "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    public static function write(string $dir, string $name, string $bytes): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $path = rtrim($dir, '/') . '/' . $name;
        file_put_contents($path, $bytes);
        return $path;
    }

    /** "%PDF-" header followed by garbage: passes the signature check, fails Poppler. */
    public static function corrupt(): string
    {
        return "%PDF-1.4\n" . str_repeat("garbage \x00\x01 not a pdf structure\n", 50);
    }

    /** A real PNG. */
    public static function png(): string
    {
        $img = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    /** Password-protected PDF via qpdf, or null when qpdf is unavailable. */
    public static function encrypted(string $dir): ?string
    {
        $qpdf = ProcessRunner::which('qpdf');
        if (!$qpdf) {
            return null;
        }
        $plain = self::write($dir, 'plain-' . bin2hex(random_bytes(3)) . '.pdf', self::make(2));
        $enc = $dir . '/enc-' . bin2hex(random_bytes(3)) . '.pdf';
        $r = ProcessRunner::run([$qpdf, '--encrypt', 'user-pass', 'owner-pass', '256', '--', $plain, $enc], 30);
        return $r['exit'] === 0 && is_file($enc) ? $enc : null;
    }
}
