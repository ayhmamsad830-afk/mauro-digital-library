<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Validators\ValidationResult;

final class SettingsController extends AdminController
{
    public function edit(Request $request): Response
    {
        return $this->adminView('admin/settings', [
            'pageTitle' => __('admin.settings'),
            'values' => [
                'catalog_per_page' => Setting::get('catalog.per_page', '24'),
                'featured_book_id' => Setting::get('home.featured_book_id', ''),
                'contact_email' => Setting::get('site.contact_email', ''),
            ],
            'perPageOptions' => config('ui.per_page_options', [12, 24, 36]),
            'books' => Database::all("SELECT id, title FROM books WHERE status = 'published' AND deleted_at IS NULL ORDER BY title LIMIT 500"),
        ]);
    }

    public function update(Request $request): Response
    {
        $errors = [];
        $perPage = (string) $request->input('catalog_per_page', '');
        if (!in_array((int) $perPage, config('ui.per_page_options', [12, 24, 36]), true)) {
            $errors['catalog_per_page'] = __('validation.invalid');
        }
        $featured = trim((string) $request->input('featured_book_id', ''));
        if ($featured !== '' && (!ctype_digit($featured) || !Database::scalar("SELECT 1 FROM books WHERE id = :id AND status = 'published' AND deleted_at IS NULL", ['id' => (int) $featured]))) {
            $errors['featured_book_id'] = __('validation.invalid');
        }
        $email = trim((string) $request->input('contact_email', ''));
        if ($email !== '' && (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['contact_email'] = __('validation.email');
        }
        if ($errors) {
            return $this->backWithErrors(route('admin.settings'), new ValidationResult([], $errors), $request);
        }
        Database::transaction(function () use ($perPage, $featured, $email, $request): void {
            Setting::set('catalog.per_page', (string) (int) $perPage, $this->adminId());
            Setting::set('home.featured_book_id', $featured !== '' ? $featured : null, $this->adminId());
            Setting::set('site.contact_email', $email !== '' ? $email : null, $this->adminId());
            ActivityLog::record($this->adminId(), 'settings.updated', 'settings', null, null, $request->ip());
        });
        return $this->success(route('admin.settings'), __('admin.saved'));
    }
}
