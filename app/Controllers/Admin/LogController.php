<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;

final class LogController extends AdminController
{
    public function index(Request $request): Response
    {
        $types = ActivityLog::entityTypes();
        $type = in_array($request->query('type'), $types, true) ? (string) $request->query('type') : null;
        return $this->adminView('admin/logs', [
            'pageTitle' => __('admin.logs'),
            'logs' => ActivityLog::paginate($type, null, max(1, (int) $request->query('page', 1))),
            'types' => $types,
            'type' => $type,
            'pageUrl' => static fn (int $p): string => route('admin.logs.index', [], array_filter(['type' => $type, 'page' => $p > 1 ? $p : null])),
        ]);
    }
}
