<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\ContactService;
use App\Services\Mail\Mailer;

/** Contact requests from the public form: read, mark read / archive (no deletion). */
final class MessageController extends AdminController
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ContactService::STATUSES, true) ? (string) $request->query('status') : null;
        return $this->adminView('admin/messages/index', [
            'pageTitle' => __('admin.messages'),
            'messages' => ContactService::paginate($status, max(1, (int) $request->query('page', 1))),
            'status' => $status,
            'emailDelivery' => Mailer::canDeliver(),
            'pageUrl' => static fn (int $p): string => route('admin.messages.index', [], array_filter(['status' => $status, 'page' => $p > 1 ? $p : null])),
        ]);
    }

    public function status(Request $request): Response
    {
        $status = (string) $request->input('status');
        ContactService::setStatus($this->idParam($request), $status, $this->adminId(), $request->ip());
        return $this->success(safe_redirect_path((string) $request->input('redirect', ''), route('admin.messages.index')), __('admin.message_' . $status));
    }
}
