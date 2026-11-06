<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\UserAdminService;

final class UserController extends AdminController
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $role = self::pick($request->query('role'), UserAdminService::ROLES);
        $status = self::pick($request->query('status'), UserAdminService::STATUSES);
        $page = max(1, (int) $request->query('page', 1));
        return $this->adminView('admin/users/index', [
            'pageTitle' => __('admin.users'),
            'users' => UserAdminService::paginate($q, $role, $status, $page),
            'filters' => ['q' => $q, 'role' => $role, 'status' => $status],
            'selfId' => $this->adminId(),
            'pageUrl' => static fn (int $p): string => route('admin.users.index', [], array_filter(['q' => $q, 'role' => $role, 'status' => $status, 'page' => $p > 1 ? $p : null])),
        ]);
    }

    public function status(Request $request): Response
    {
        return $this->apply($request, fn () => UserAdminService::setStatus($this->idParam($request), (string) $request->input('status'), $this->adminId(), $request->ip()));
    }

    public function role(Request $request): Response
    {
        return $this->apply($request, fn () => UserAdminService::setRole($this->idParam($request), (string) $request->input('role'), $this->adminId(), $request->ip()));
    }

    private function apply(Request $request, callable $fn): Response
    {
        $back = safe_redirect_path((string) $request->input('redirect', ''), route('admin.users.index'));
        try {
            $fn();
        } catch (HttpException $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->failure($back, $e->getMessage());
        }
        return $this->success($back, __('admin.saved'));
    }

    private static function pick(mixed $v, array $allowed): ?string
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : null;
    }
}
