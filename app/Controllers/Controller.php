<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Contracts\LibraryRepository;
use App\Core\HttpException;
use App\Core\Response;
use App\Repositories\LibraryRepositoryFactory;
use App\Core\View;

abstract class Controller
{
    protected function view(string $template, array $data = [], int $status = 200, ?string $layout = 'layouts/main'): Response
    {
        return Response::html(View::render($template, $data, $layout), $status);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function library(): LibraryRepository
    {
        return LibraryRepositoryFactory::make();
    }

    /** Design previews (account/admin/styleguide) exist only in demo mode until their real phase. */
    protected function requireDemo(): void
    {
        if (!config('ui.demo', false)) {
            throw HttpException::notFound();
        }
    }

    protected function redirect(string $path, int $status = 302): Response
    {
        return Response::redirect($path, $status);
    }
}
