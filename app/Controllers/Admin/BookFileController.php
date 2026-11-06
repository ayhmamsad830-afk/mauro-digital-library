<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Pdf\PdfUploadService;
use App\Services\Pdf\RenderService;
use App\Services\RateLimiter;

/** PDF upload / replacement and render control for one book (admin only). */
final class BookFileController extends AdminController
{
    /** POST /admin/books/{id}/replace-pdf — first upload or new version (spec §12.3). */
    public function upload(Request $request): Response
    {
        $id = $this->idParam($request);
        $back = route('admin.books.edit', ['id' => $id]);
        RateLimiter::enforce('admin_upload', 'admin:' . $this->adminId());
        $file = $request->file('pdf');
        [$fileId, $errors] = PdfUploadService::store(
            $id,
            is_array($file) && is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : null,
            is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : '',
            is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE,
            $this->adminId(),
            $request->ip()
        );
        if ($fileId === null) {
            return $this->failure($back, implode(' ', $errors));
        }
        return $this->success($back, __('pdf.uploaded'));
    }

    /** POST /admin/books/{id}/files/{fileId}/process — render now (small files), else leave for the worker. */
    public function process(Request $request): Response
    {
        $id = $this->idParam($request);
        $back = route('admin.books.edit', ['id' => $id]);
        $file = $this->fileOf($id, (int) $request->param('fileId'));
        $job = Database::scalar("SELECT id FROM book_render_jobs WHERE book_file_id = :f AND status = 'queued' ORDER BY id DESC LIMIT 1", ['f' => $file['id']]);
        if (!$job) {
            return $this->failure($back, __('pdf.nothing_queued'));
        }
        if ((int) $file['page_count'] > (int) Config::get('rendering.sync_max_pages', 60)) {
            return $this->failure($back, __('pdf.use_worker', ['max' => (int) Config::get('rendering.sync_max_pages', 60)]));
        }
        @set_time_limit(300);
        if (!RenderService::claim((int) $job, 'web-' . $this->adminId())) {
            return $this->failure($back, __('pdf.nothing_queued'));
        }
        $result = (new RenderService())->process((int) $job);
        return $result['ok']
            ? $this->success($back, __('pdf.rendered', ['pages' => $result['pages']]))
            : $this->failure($back, __('pdf.render_failed') . ' ' . $result['error']);
    }

    /** POST /admin/books/{id}/files/{fileId}/retry — re-queue a failed render. */
    public function retry(Request $request): Response
    {
        $id = $this->idParam($request);
        $file = $this->fileOf($id, (int) $request->param('fileId'));
        $ok = RenderService::retry((int) $file['id'], $this->adminId(), $request->ip());
        return $ok ? $this->success(route('admin.books.edit', ['id' => $id]), __('pdf.requeued'))
                   : $this->failure(route('admin.books.edit', ['id' => $id]), __('pdf.nothing_queued'));
    }

    private function fileOf(int $bookId, int $fileId): array
    {
        $f = Database::first('SELECT id, book_id, page_count FROM book_files WHERE id = :f AND book_id = :b', ['f' => $fileId, 'b' => $bookId]);
        return $f ?: throw HttpException::notFound();
    }
}
