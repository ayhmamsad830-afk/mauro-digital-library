<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;
use App\Services\ContactService;
use App\Services\Mail\Mailer;

/**
 * GET  /contact   form (+ honest notice of how the message will reach the team)
 * POST /contact   CSRF + rate limit; stored in contact_messages; Post/Redirect/Get on success
 * GET  /about     platform presentation (no invented personal or organisational claims)
 */
final class ContactController extends Controller
{
    public function show(Request $request): Response
    {
        $user = Auth::user();
        return $this->contactView([
            'sent' => Session::getFlash('contact_sent'),
            'values' => ['name' => (string) ($user['name'] ?? ''), 'email' => (string) ($user['email'] ?? ''), 'subject' => '', 'message' => ''],
        ]);
    }

    public function submit(Request $request): Response
    {
        $in = [];
        foreach (array_keys(ContactService::LIMITS) as $k) {
            $in[$k] = is_string($request->input($k)) ? mb_substr((string) $request->input($k), 0, ContactService::LIMITS[$k][1] + 50) : '';
        }
        // Honeypot: real visitors never fill this hidden field; bots get a neutral answer, nothing is stored.
        if (is_string($request->input('website')) && trim((string) $request->input('website')) !== '') {
            Session::flash('contact_sent', 'not_configured');
            return $this->redirect('/contact', 303);
        }
        $result = ContactService::submit($in, $request->ip(), Auth::id(), locale());
        if ($result['ok']) {
            Session::flash('contact_sent', (string) $result['delivery']);
            return $this->redirect('/contact', 303);
        }
        $response = $this->contactView([
            'sent' => null,
            'values' => $in,
            'errors' => $result['errors'],
            'error' => $result['reason'] === 'throttled' ? __('contact.too_many') : __('contact.fix_errors'),
        ], $result['reason'] === 'throttled' ? 429 : 422);
        if ($result['reason'] === 'throttled') {
            $response->header('Retry-After', '3600');
        }
        return $response;
    }

    public function about(Request $request): Response
    {
        return $this->view('pages/about', ['pageTitle' => __('about.title', ['brand' => brand_name()]), 'metaDescription' => __('about.meta', ['brand' => brand_name()])]);
    }

    private function contactView(array $data, int $status = 200): Response
    {
        return $this->view('pages/contact', $data + [
            'pageTitle' => __('contact.title'),
            'metaDescription' => __('contact.meta', ['brand' => brand_name()]),
            'errors' => [],
            'error' => null,
            'emailDelivery' => Mailer::canDeliver(),
        ], $status);
    }
}
