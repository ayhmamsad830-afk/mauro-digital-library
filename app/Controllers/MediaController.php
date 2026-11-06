<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\BookAccessPolicy;
use App\Services\Media\ImageUploadService;

/**
 * Serves re-encoded cover / author images from private storage. The book's
 * visibility (status, schedule, members-only) is checked on every request,
 * so a hidden book's cover is a 404 exactly like the book itself.
 */
final class MediaController extends Controller
{
    public function cover(Request $request): Response
    {
        $book = $this->library()->findBookById((int) $request->param('id'));
        if ($book === null || !BookAccessPolicy::canView($book) || empty($book['cover_path'])) {
            throw HttpException::notFound();
        }
        return $this->image((string) $book['cover_path'], ($book['visibility'] ?? 'public') === 'public');
    }

    public function authorPhoto(Request $request): Response
    {
        $id = (int) $request->param('id');
        foreach ($this->library()->authors() as $a) { // only authors with at least one visible book
            if ($a['id'] === $id && !empty($a['photo_path'])) {
                return $this->image((string) $a['photo_path'], true);
            }
        }
        throw HttpException::notFound();
    }

    private function image(string $relative, bool $public): Response
    {
        $abs = ImageUploadService::absolute($relative) ?? throw HttpException::notFound();
        return new Response((string) file_get_contents($abs), 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => $public ? 'public, max-age=86400' : 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
