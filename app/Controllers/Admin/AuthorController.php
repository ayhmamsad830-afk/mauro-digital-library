<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Author;
use App\Services\Admin\AuthorService;
use App\Support\BookQuery;

final class AuthorController extends AdminController
{
    public function index(Request $request): Response
    {
        $q = BookQuery::cleanText((string) $request->query('q', ''));
        return $this->adminView('admin/authors/index', [
            'pageTitle' => __('admin.authors'),
            'authors' => Author::allWithCounts($q),
            'q' => $q,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        [$id, $v] = AuthorService::create($request->all(), $this->adminId(), $request->ip());
        if ($id === null) {
            return $this->backWithErrors(route('admin.authors.create'), $v, $request);
        }
        return $this->success(route('admin.authors.index'), __('admin.saved'));
    }

    public function edit(Request $request): Response
    {
        return $this->form(Author::find($this->idParam($request)) ?? throw HttpException::notFound());
    }

    public function update(Request $request): Response
    {
        $id = $this->idParam($request);
        $v = AuthorService::update($id, $request->all(), $this->adminId(), $request->ip());
        if ($v->fails()) {
            return $this->backWithErrors(route('admin.authors.edit', ['id' => $id]), $v, $request);
        }
        return $this->success(route('admin.authors.index'), __('admin.saved'));
    }

    private function form(?array $author): Response
    {
        return $this->adminView('admin/authors/form', [
            'pageTitle' => $author ? __('admin.edit_author') : __('admin.add_author'),
            'author' => $author,
        ]);
    }
}
