<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\AnalyticsService;

final class AnalyticsController extends AdminController
{
    public function index(Request $request): Response
    {
        $days = in_array((int) $request->query('days', 30), [7, 30, 90], true) ? (int) $request->query('days', 30) : 30;
        return $this->adminView('admin/analytics', [
            'pageTitle' => __('admin.analytics'),
            'report' => AnalyticsService::report($days),
        ]);
    }
}
