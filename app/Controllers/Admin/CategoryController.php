<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Category;
use App\Services\Admin\CategoryService;

final class CategoryController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->adminView('admin/categories/index', [
            'pageTitle' => __('admin.categories'),
            'categories' => Category::allWithCounts(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        [$id, $v] = CategoryService::create($request->all(), $this->adminId(), $request->ip());
        if ($id === null) {
            return $this->backWithErrors(route('admin.categories.create'), $v, $request);
        }
        return $this->success(route('admin.categories.index'), __('admin.saved'));
    }

    public function edit(Request $request): Response
    {
        return $this->form(Category::find($this->idParam($request)) ?? throw HttpException::notFound());
    }

    public function update(Request $request): Response
    {
        $id = $this->idParam($request);
        $v = CategoryService::update($id, $request->all(), $this->adminId(), $request->ip());
        if ($v->fails()) {
            return $this->backWithErrors(route('admin.categories.edit', ['id' => $id]), $v, $request);
        }
        return $this->success(route('admin.categories.index'), __('admin.saved'));
    }

    public function toggle(Request $request): Response
    {
        $status = CategoryService::toggle($this->idParam($request), $this->adminId(), $request->ip());
        return $this->success(route('admin.categories.index'), $status === 'active' ? __('admin.category_activated') : __('admin.category_deactivated'));
    }

    private function form(?array $category): Response
    {
        return $this->adminView('admin/categories/form', [
            'pageTitle' => $category ? __('admin.edit_category') : __('admin.add_category'),
            'category' => $category,
            'parents' => array_values(array_filter(Category::options(), static fn ($c) => !$category || (int) $c['id'] !== (int) $category['id'])),
        ]);
    }
}
