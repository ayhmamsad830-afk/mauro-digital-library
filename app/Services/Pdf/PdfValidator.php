<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Core\Config;
use Throwable;

/**
 * Validates an uploaded PDF BEFORE it is stored (spec §13.3):
 *  - PHP upload error codes and size limits (configured max, non-empty)
 *  - .pdf extension (case-insensitive) — necessary but never sufficient
 *  - MIME sniffed from the bytes with finfo (the browser's Content-Type is ignored)
 *  - "%PDF-" signature at the start of the file
 *  - Poppler can open it: page count ≥ 1 and ≤ configured maximum
 *  - not password-encrypted (it could not be rendered unattended)
 */
final class PdfValidator
{
    /**
     * @return array{ok:bool, errors:list<string>, pages:int, sha256:string, size:int, inspect?:array}
     */
    public static function validate(?string $tmpPath, string $originalName, int $uploadError = UPLOAD_ERR_OK): array
    {
        $fail = static fn (string ...$e) => ['ok' => false, 'errors' => $e, 'pages' => 0, 'sha256' => '', 'size' => 0];
        $max = (int) Config::get('storage.pdf_max_bytes', 10 * 1048576);
        $maxMb = round($max / 1048576, 1);

        switch ($uploadError) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $fail(__('pdf.too_large', ['max' => $maxMb]));
            case UPLOAD_ERR_NO_FILE:
                return $fail(__('pdf.no_file'));
            case UPLOAD_ERR_PARTIAL:
                return $fail(__('pdf.partial'));
            default:
                return $fail(__('pdf.upload_failed'));
        }
        if ($tmpPath === null || !is_file($tmpPath)) {
            return $fail(__('pdf.no_file'));
        }

        $size = (int) filesize($tmpPath);
        if ($size === 0) {
            return $fail(__('pdf.empty'));
        }
        if ($size > $max) {
            return $fail(__('pdf.too_large', ['max' => $maxMb]));
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
            return $fail(__('pdf.bad_extension'));
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: '';
        $head = (string) file_get_contents($tmpPath, false, null, 0, 1024);
        if ($mime !== 'application/pdf' || !str_starts_with(ltrim($head, "\xEF\xBB\xBF\r\n\t "), '%PDF-')) {
            return $fail(__('pdf.not_pdf'));
        }

        try {
            $info = PdfInspector::inspect($tmpPath);
        } catch (Throwable $e) {
            return $fail($e->getCode() === PdfInspector::ENCRYPTED ? __('pdf.encrypted') : __('pdf.unreadable'));
        }
        if ($info['encrypted']) {
            return $fail(__('pdf.encrypted'));
        }
        $maxPages = (int) Config::get('rendering.max_pages', 2000);
        if ($info['pages'] < 1) {
            return $fail(__('pdf.unreadable'));
        }
        if ($info['pages'] > $maxPages) {
            return $fail(__('pdf.too_many_pages', ['max' => $maxPages]));
        }

        return ['ok' => true, 'errors' => [], 'pages' => $info['pages'], 'sha256' => (string) hash_file('sha256', $tmpPath), 'size' => $size, 'inspect' => $info];
    }
}
