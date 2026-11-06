<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\HostCapabilityService;
use App\Services\Storage\PrivateStorage;

/** Overview of PDF versions and rendering jobs across all books (no paths or original names exposed beyond admins). */
final class FilesController extends AdminController
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['pending', 'processing', 'ready', 'failed'], true) ? (string) $request->query('status') : null;
        return $this->adminView('admin/files', [
            'pageTitle' => __('admin.files'),
            'status' => $status,
            'files' => Database::all(
                'SELECT f.id, f.book_id, f.version_number, f.render_status, f.validation_status, f.page_count, f.file_size, f.created_at, f.rendered_at,
                        b.title, b.current_file_id, j.status AS job_status, j.pages_done, j.attempts, j.last_error
                   FROM book_files f JOIN books b ON b.id = f.book_id
                   LEFT JOIN book_render_jobs j ON j.id = (SELECT MAX(j2.id) FROM book_render_jobs j2 WHERE j2.book_file_id = f.id)
                  WHERE b.deleted_at IS NULL' . ($status ? ' AND f.render_status = :st' : '') . '
                  ORDER BY f.created_at DESC, f.id DESC LIMIT 200',
                $status ? ['st' => $status] : []
            ),
            'freeMb' => PrivateStorage::freeMb(),
        ]);
    }
}
