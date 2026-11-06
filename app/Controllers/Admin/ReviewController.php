<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\ReviewAdminService;

final class ReviewController extends AdminController
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['published', 'hidden'], true) ? (string) $request->query('status') : null;
        return $this->adminView('admin/reviews/index', [
            'pageTitle' => __('admin.reviews'),
            'reviews' => ReviewAdminService::paginate($status, max(1, (int) $request->query('page', 1))),
            'status' => $status,
            'pageUrl' => static fn (int $p): string => route('admin.reviews.index', [], array_filter(['status' => $status, 'page' => $p > 1 ? $p : null])),
        ]);
    }

    public function moderate(Request $request): Response
    {
        $status = (string) $request->input('status');
        ReviewAdminService::setStatus($this->idParam($request), $status, $this->adminId(), $request->ip());
        return $this->success(safe_redirect_path((string) $request->input('redirect', ''), route('admin.reviews.index')),
            $status === 'hidden' ? __('admin.review_hidden') : __('admin.review_published'));
    }
}
