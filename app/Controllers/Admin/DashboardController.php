<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\ActivityLog;
use App\Models\Book;

final class DashboardController extends AdminController
{
    /** Real dashboard (admins only): figures come from MySQL. */
    public function index(Request $request): Response
    {
        $counts = Book::statusCounts();
        $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        return $this->adminView('admin/home', [
            'pageTitle' => __('admin.dashboard'),
            'stats' => [
                ['label' => __('admin.stat_published'), 'value' => $counts['published'], 'icon' => 'book'],
                ['label' => __('admin.stat_drafts'), 'value' => $counts['draft'], 'icon' => 'pen'],
                ['label' => __('admin.stat_users'), 'value' => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'active'"), 'icon' => 'users'],
                ['label' => __('admin.stat_sessions'), 'value' => (int) Database::scalar('SELECT COUNT(*) FROM reading_sessions WHERE started_at >= :s', ['s' => $since]), 'icon' => 'chart'],
            ],
            'recent' => Book::paginate('', null, 1, 6)->items,
            'activity' => ActivityLog::recent(8),
            'renderJobs' => Database::all(
                'SELECT j.status, j.pages_done, f.page_count, b.title FROM book_render_jobs j
                   JOIN book_files f ON f.id = j.book_file_id JOIN books b ON b.id = f.book_id
                  ORDER BY j.created_at DESC, j.id DESC LIMIT 5'
            ),
        ]);
    }

    /** DESIGN PREVIEW with fictional figures — demo mode only (/demo/admin). */
    public function preview(Request $request): Response
    {
        $this->requireDemo();
        $books = $this->library()->latestBooks(6);

        return Response::html(View::render('admin/dashboard', [
            'pageTitle' => __('admin.dashboard'),
            'preview' => true,
            'stats' => [
                ['label' => __('admin.stat_published'), 'value' => 14, 'icon' => 'book'],
                ['label' => __('admin.stat_drafts'), 'value' => 3, 'icon' => 'pen'],
                ['label' => __('admin.stat_users'), 'value' => 286, 'icon' => 'users'],
                ['label' => __('admin.stat_sessions'), 'value' => 1204, 'icon' => 'chart'],
            ],
            'books' => $books,
            'statuses' => ['published', 'published', 'draft', 'published', 'archived', 'published'],
            'renderJobs' => [
                ['title' => $books[0]['title'], 'status' => 'completed', 'pages' => '248 / 248'],
                ['title' => $books[1]['title'], 'status' => 'processing', 'pages' => '120 / 312'],
                ['title' => $books[3]['title'], 'status' => 'failed', 'pages' => '0 / 402'],
            ],
            'activity' => [
                ['action' => __('admin.act_published'), 'entity' => $books[0]['title'], 'time' => '2026-11-05 14:12'],
                ['action' => __('admin.act_uploaded'), 'entity' => $books[1]['title'], 'time' => '2026-11-05 13:40'],
                ['action' => __('admin.act_review_hidden'), 'entity' => $books[2]['title'], 'time' => '2026-11-04 18:03'],
            ],
            'noindex' => true,
        ], 'layouts/admin'));
    }
}
