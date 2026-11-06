<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/** Privacy / Terms / Copyright pages (spec §6.1 footer, §13.6). Texts are drafts pending legal review. */
final class PageController extends Controller
{
    public function privacy(Request $request): Response
    {
        return $this->page('privacy', 4);
    }

    public function terms(Request $request): Response
    {
        return $this->page('terms', 4);
    }

    public function copyright(Request $request): Response
    {
        return $this->page('copyright', 3);
    }

    private function page(string $key, int $sections): Response
    {
        return $this->view('pages/legal', [
            'pageTitle' => __("pages.$key.title"),
            'key' => $key,
            'sections' => $sections,
            // Admin setting (Settings page); never in demo mode, where no database is used.
            'contactEmail' => $key === 'copyright' && !$this->library()->isDemo() ? \App\Models\Setting::get('site.contact_email') : null,
        ]);
    }
}
