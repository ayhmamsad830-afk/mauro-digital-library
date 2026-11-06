<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Support\Paginator;

/** Living design-system reference (demo / non-production only). */
final class StyleguideController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requireDemo();
        $library = $this->library();
        return $this->view('styleguide/index', [
            'pageTitle' => __('styleguide.title'),
            'noindex' => true,
            'books' => $library->latestBooks(4),
            'categories' => array_slice($library->categories(), 0, 3),
            'paginator' => new Paginator([], 240, 6, 24),
        ]);
    }
}
