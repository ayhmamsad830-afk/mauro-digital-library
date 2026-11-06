<?php

declare(strict_types=1);

namespace App\Services\Pdf;

/** Converts a page range of a PDF into PNG files (server side). */
interface Rasterizer
{
    /**
     * Render pages $from..$to (1-based, inclusive) at the given pixel width.
     *
     * @return array<int, string> page number => absolute PNG path, in order
     * @throws \RuntimeException on any failure
     */
    public function rasterize(string $pdfPath, int $from, int $to, string $outDir, int $width): array;

    public function name(): string;
}
