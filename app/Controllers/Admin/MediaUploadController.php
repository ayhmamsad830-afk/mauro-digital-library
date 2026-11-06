<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Media\ImageUploadService;

final class MediaUploadController extends AdminController
{
    public function cover(Request $request): Response
    {
        $id = $this->idParam($request);
        \App\Services\RateLimiter::enforce('admin_upload', 'admin:' . $this->adminId());
        $f = $request->file('cover');
        $errors = ImageUploadService::cover($id, $f['tmp_name'] ?? null, (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE), $this->adminId(), $request->ip());
        return $errors ? $this->failure(route('admin.books.edit', ['id' => $id]) . '#cover', implode(' ', $errors))
                       : $this->success(route('admin.books.edit', ['id' => $id]) . '#cover', __('media.cover_saved'));
    }

    public function removeCover(Request $request): Response
    {
        $id = $this->idParam($request);
        ImageUploadService::removeCover($id, $this->adminId(), $request->ip());
        return $this->success(route('admin.books.edit', ['id' => $id]) . '#cover', __('media.cover_removed'));
    }

    public function authorPhoto(Request $request): Response
    {
        $id = $this->idParam($request);
        \App\Services\RateLimiter::enforce('admin_upload', 'admin:' . $this->adminId());
        $f = $request->file('photo');
        $errors = ImageUploadService::authorPhoto($id, $f['tmp_name'] ?? null, (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE), $this->adminId(), $request->ip());
        return $errors ? $this->failure(route('admin.authors.edit', ['id' => $id]), implode(' ', $errors))
                       : $this->success(route('admin.authors.edit', ['id' => $id]), __('media.photo_saved'));
    }

    /** Admin-only preview (any book status); the public route enforces visibility. */
    public function showCover(Request $request): Response
    {
        return $this->preview(Database::scalar('SELECT cover_path FROM books WHERE id = :id', ['id' => $this->idParam($request)]));
    }

    public function showAuthorPhoto(Request $request): Response
    {
        return $this->preview(Database::scalar('SELECT photo_path FROM authors WHERE id = :id', ['id' => $this->idParam($request)]));
    }

    private function preview(mixed $relative): Response
    {
        $abs = ImageUploadService::absolute(is_string($relative) ? $relative : null) ?? throw HttpException::notFound();
        return new Response((string) file_get_contents($abs), 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
