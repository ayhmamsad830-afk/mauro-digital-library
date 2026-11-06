<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Auth;
use App\Validators\ValidationResult;

/** Base for authenticated admin pages (routes are behind AuthMiddleware + AdminMiddleware). */
abstract class AdminController extends Controller
{
    protected function adminView(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(View::render($template, $data + ['adminUser' => Auth::user(), 'noindex' => true], 'layouts/admin'), $status);
    }

    protected function adminId(): int
    {
        return (int) Auth::id();
    }

    /** Back to the form with errors and the submitted values (never file data or tokens). */
    protected function backWithErrors(string $url, ValidationResult $v, Request $request): Response
    {
        $old = $request->all();
        unset($old['_token'], $old['_method']);
        Session::flash('errors', $v->errors);
        Session::flash('old', $old);
        Session::flash('error', __('admin.fix_errors'));
        return $this->redirect($url, 303);
    }

    protected function success(string $url, string $message): Response
    {
        Session::flash('success', $message);
        return $this->redirect($url, 303);
    }

    protected function failure(string $url, string $message): Response
    {
        Session::flash('error', $message);
        return $this->redirect($url, 303);
    }

    protected function idParam(Request $request): int
    {
        return (int) $request->param('id');
    }
}
