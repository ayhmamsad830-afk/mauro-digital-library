<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\BackupStatusService;

/**
 * Read-only backup status. Backups are created by `bin/console backup:run` (cron/CLI);
 * this screen never runs, restores, deletes or serves a backup file.
 */
final class BackupController extends AdminController
{
    public function index(Request $request): Response
    {
        $list = BackupStatusService::list();
        return $this->adminView('admin/backups', [
            'pageTitle' => __('admin.backups'),
            'backups' => $list,
            'latest' => BackupStatusService::latestDatabaseBackup($list),
        ]);
    }
}
