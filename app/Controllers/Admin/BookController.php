<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use App\Services\Admin\BookService;
use App\Support\BookQuery;
use App\Validators\BookValidator;
use DomainException;

final class BookController extends AdminController
{
    public function index(Request $request): Response
    {
        $q = BookQuery::cleanText((string) $request->query('q', ''));
        $status = in_array($request->query('status'), Book::STATUSES, true) ? (string) $request->query('status') : null;
        $page = max(1, (int) (is_string($request->query('page')) ? $request->query('page') : 1));
        $result = Book::paginate($q, $status, $page);

        return $this->adminView('admin/books/index', [
            'pageTitle' => __('admin.books'),
            'books' => $result,
            'q' => $q,
            'status' => $status,
            'counts' => Book::statusCounts(),
            'pageUrl' => static fn (int $p): string => route('admin.books.index', [], array_filter(['q' => $q, 'status' => $status, 'page' => $p > 1 ? $p : null])),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        [$id, $v] = BookService::create($request->all(), $this->adminId(), $request->ip());
        if ($id === null) {
            return $this->backWithErrors(route('admin.books.create'), $v, $request);
        }
        return $this->success(route('admin.books.edit', ['id' => $id]), __('admin.book_created'));
    }

    public function edit(Request $request): Response
    {
        $book = Book::find($this->idParam($request)) ?? throw HttpException::notFound();
        return $this->form($book);
    }

    public function update(Request $request): Response
    {
        $id = $this->idParam($request);
        $v = BookService::update($id, $request->all(), $this->adminId(), $request->ip());
        if ($v->fails()) {
            return $this->backWithErrors(route('admin.books.edit', ['id' => $id]), $v, $request);
        }
        return $this->success(route('admin.books.edit', ['id' => $id]), __('admin.saved'));
    }

    public function publish(Request $request): Response
    {
        $id = $this->idParam($request);
        $blockers = BookService::publish($id, $this->adminId(), $request->ip());
        if ($blockers !== []) {
            return $this->failure(route('admin.books.edit', ['id' => $id]), __('admin.cannot_publish') . ' ' . implode(' · ', $blockers));
        }
        return $this->success(route('admin.books.edit', ['id' => $id]), __('admin.book_published'));
    }

    public function unpublish(Request $request): Response
    {
        return $this->transition($request, 'unpublish', __('admin.book_unpublished'));
    }

    public function archive(Request $request): Response
    {
        return $this->transition($request, 'archive', __('admin.book_archived'));
    }

    public function restore(Request $request): Response
    {
        return $this->transition($request, 'restore', __('admin.book_restored'));
    }

    private function transition(Request $request, string $method, string $message): Response
    {
        $id = $this->idParam($request);
        try {
            BookService::$method($id, $this->adminId(), $request->ip());
        } catch (DomainException $e) {
            return $this->failure(route('admin.books.edit', ['id' => $id]), $e->getMessage());
        }
        return $this->success(route('admin.books.edit', ['id' => $id]), $message);
    }

    private function form(?array $book): Response
    {
        return $this->adminView('admin/books/form', [
            'pageTitle' => $book ? __('admin.edit_book') : __('admin.add_book'),
            'book' => $book,
            'authors' => Author::options(),
            'categories' => Category::options(),
            'languages' => BookValidator::LANGUAGES,
            'blockers' => $book ? BookService::publishBlockers($book) : [],
            'versions' => $book ? \App\Models\BookFile::versionsFor((int) $book['id']) : [],
            'maxUploadMb' => round(min((int) \App\Core\Config::get('storage.pdf_max_bytes'), \App\Services\HostCapabilityService::iniBytes((string) ini_get('upload_max_filesize'))) / 1048576, 1),
            'syncMaxPages' => (int) \App\Core\Config::get('rendering.sync_max_pages', 60),
        ]);
    }
}
