<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Kernel;
use App\Core\Translator;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('e')) {
    /** Escape for HTML text and attribute contexts. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return Translator::get($key, $replace);
    }
}

if (!function_exists('locale')) {
    function locale(): string
    {
        return Translator::locale();
    }
}

if (!function_exists('text_direction')) {
    function text_direction(): string
    {
        return Translator::direction();
    }
}

if (!function_exists('url')) {
    /** Absolute-path URL for an application path. */
    function url(string $path = '/', array $query = []): string
    {
        $path = '/' . ltrim($path, '/');
        return $query ? $path . '?' . http_build_query($query) : $path;
    }
}

if (!function_exists('route')) {
    function route(string $name, array $params = [], array $query = []): string
    {
        return Kernel::router()->url($name, $params, $query);
    }
}

if (!function_exists('asset')) {
    /** Versioned public asset URL (cache busting by file mtime). */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = Config::get('app.public_path', BASE_PATH . '/public') . '/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '0';
        return '/' . $path . '?v=' . $version;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        $base = (string) Config::get('storage.root', BASE_PATH . '/storage');
        return rtrim($base, '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('safe_redirect_path')) {
    /**
     * Accept only same-site relative paths ("/x"), rejecting scheme-relative
     * ("//evil") and backslash tricks, to prevent open redirects.
     */
    function safe_redirect_path(?string $candidate, string $fallback = '/'): string
    {
        if (!is_string($candidate) || $candidate === '' || strlen($candidate) > 2000) {
            return $fallback;
        }
        if ($candidate[0] !== '/' || str_starts_with($candidate, '//') || str_contains($candidate, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
            return $fallback;
        }
        return $candidate;
    }
}

if (!function_exists('localized')) {
    /**
     * Pick the current-locale variant of a bilingual field, e.g. localized($cat, 'name')
     * reads name_ar / name_en and falls back to the other language.
     */
    function localized(array $row, string $field): string
    {
        $primary = $row[$field . '_' . Translator::locale()] ?? null;
        if (is_string($primary) && $primary !== '') {
            return $primary;
        }
        foreach (Translator::supported() as $loc) {
            if (!empty($row[$field . '_' . $loc])) {
                return (string) $row[$field . '_' . $loc];
            }
        }
        return (string) ($row[$field] ?? '');
    }
}

if (!function_exists('is_current_path')) {
    /** True when the current request path equals $path (or is under it when $prefix). */
    function is_current_path(string $path, bool $prefix = false): bool
    {
        $current = (string) \App\Core\View::shared('currentPath', '/');
        return $prefix ? ($current === $path || str_starts_with($current, rtrim($path, '/') . '/')) : $current === $path;
    }
}

if (!function_exists('current_url')) {
    /** Current same-origin path + query (validated), e.g. for "redirect back" fields. */
    function current_url(): string
    {
        return safe_redirect_path((string) \App\Core\View::shared('currentUri', '/'), '/');
    }
}

if (!function_exists('format_date')) {
    /** Locale-aware long date (Gregorian, Western digits). */
    function format_date(?string $date): string
    {
        if (!$date) {
            return '';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return '';
        }
        if (class_exists(\IntlDateFormatter::class)) {
            $fmt = new \IntlDateFormatter(Translator::locale() === 'ar' ? 'ar-u-nu-latn' : 'en', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, 'UTC');
            $out = $fmt->format($ts);
            if (is_string($out)) {
                return $out;
            }
        }
        return date('Y-m-d', $ts);
    }
}

if (!function_exists('format_number')) {
    function format_number(int|float $n, int $decimals = 0): string
    {
        return number_format($n, $decimals, '.', ',');
    }
}

if (!function_exists('language_name')) {
    function language_name(string $code): string
    {
        $key = 'lang.' . $code;
        $label = Translator::get($key);
        return $label === $key ? strtoupper($code) : $label;
    }
}

if (!function_exists('icon')) {
    /**
     * Inline SVG icon from a small fixed set (stroke icons, currentColor).
     * Decorative by default (aria-hidden); pair with visible text or aria-label.
     */
    function icon(string $name, string $class = ''): string
    {
        static $paths = [
            'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
            'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
            'sun' => '<circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.2M12 19.3v2.2M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6"/>',
            'moon' => '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/>',
            'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/>',
            'book' => '<path d="M4 5.5C6.8 4.6 9.5 5 12 6.8c2.5-1.8 5.2-2.2 8-1.3v13c-2.8-.9-5.5-.5-8 1.3-2.5-1.8-5.2-2.2-8-1.3z"/><path d="M12 6.8v13"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 20c1.4-3.4 4.2-5 7.5-5s6.1 1.6 7.5 5"/>',
            'heart' => '<path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.3a4.3 4.3 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z"/>',
            'bookmark' => '<path d="M6.5 4h11v16l-5.5-4-5.5 4z"/>',
            'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
            'star' => '<path d="m12 4 2.4 5 5.4.6-4 3.7 1.1 5.3L12 16l-4.9 2.6 1.1-5.3-4-3.7 5.4-.6z"/>',
            'chevron-next' => '<path d="m9 6 6 6-6 6"/>',
            'chevron-prev' => '<path d="m15 6-6 6 6 6"/>',
            'arrow-next' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'arrow-prev' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
            'zoom-in' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2M11 8v6M8 11h6"/>',
            'zoom-out' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2M8 11h6"/>',
            'fit-width' => '<path d="M4 5v14M20 5v14M8 12h8M10 9.5 7.5 12l2.5 2.5M14 9.5l2.5 2.5-2.5 2.5"/>',
            'fit-page' => '<rect x="6" y="3.5" width="12" height="17" rx="1.5"/><path d="M9.5 8h5M9.5 12h5M9.5 16h3"/>',
            'fullscreen' => '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
            'grid' => '<rect x="4" y="4" width="6.5" height="6.5" rx="1"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1"/>',
            'shield' => '<path d="M12 3.5 5 6v5.5c0 4.4 3 7.7 7 9 4-1.3 7-4.6 7-9V6z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
            'layers' => '<path d="m12 4 8.5 4.5L12 13 3.5 8.5z"/><path d="m3.5 12.5 8.5 4.5 8.5-4.5M3.5 16.5 12 21l8.5-4.5"/>',
            'filter' => '<path d="M4 6h16M7 12h10M10 18h4"/>',
            'dashboard' => '<rect x="3.5" y="3.5" width="7" height="9" rx="1.2"/><rect x="13.5" y="3.5" width="7" height="5" rx="1.2"/><rect x="13.5" y="11.5" width="7" height="9" rx="1.2"/><rect x="3.5" y="15.5" width="7" height="5" rx="1.2"/>',
            'users' => '<circle cx="9" cy="8.5" r="3.5"/><path d="M2.5 19.5c1-3 3.5-4.5 6.5-4.5s5.5 1.5 6.5 4.5"/><path d="M16 5.2a3.5 3.5 0 0 1 0 6.6M18 15.3c1.7.6 2.9 2 3.5 4.2"/>',
            'tag' => '<path d="M3.5 12.2V4.5a1 1 0 0 1 1-1h7.7l8.3 8.3a1 1 0 0 1 0 1.4l-7.3 7.3a1 1 0 0 1-1.4 0z"/><circle cx="8" cy="8" r="1.4"/>',
            'pen' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
            'chart' => '<path d="M4 20V4M4 20h16"/><path d="M8 16v-4M12 16V8M16 16v-6"/>',
            'list' => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
            'upload' => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
            'logout' => '<path d="M14 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4"/><path d="M10 16l-4-4 4-4M6 12h10"/>',
            'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
            'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
            'alert' => '<path d="M12 4 2.8 19.5h18.4z"/><path d="M12 10v4.5M12 17h.01"/>',
            'eye' => '<path d="M2.5 12C3.5 10.5 7 6 12 6s8.5 4.5 9.5 6c-1 1.5-4.5 6-9.5 6s-8.5-4.5-9.5-6z"/><circle cx="12" cy="12" r="3"/>',
            'lock' => '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>',
            'eye-off' => '<path d="M3 3l18 18M10.6 6.1A9.6 9.6 0 0 1 12 6c5 0 8.5 4.5 9.5 6-.5.8-1.6 2.3-3.2 3.6M6.4 7.6C4.5 8.9 3.1 10.8 2.5 12c1 1.5 4.5 6 9.5 6 1.4 0 2.7-.3 3.8-.8"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        ];
        $svg = $paths[$name] ?? $paths['info'];
        return '<svg class="icon' . ($class !== '' ? ' ' . e($class) : '') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $svg . '</svg>';
    }
}

if (!function_exists('component')) {
    /** Render a reusable UI component from resources/views/components. */
    function component(string $name, array $data = []): string
    {
        return \App\Core\View::partial('components/' . $name, $data);
    }
}

if (!function_exists('book_authors')) {
    /** Author names for a book array, in stored order, joined for the locale ("A، B" / "A and B"). */
    function book_authors(array $book): string
    {
        $names = array_column($book['authors'] ?? [], 'name');
        $out = '';
        foreach ($names as $i => $name) {
            $out .= ($i > 0 ? author_separator($i, count($names)) : '') . $name;
        }
        return $out;
    }
}

if (!function_exists('author_separator')) {
    /** Separator placed before author #$i (0-based) of $count: Arabic comma, or English ", " / " and ". */
    function author_separator(int $i, int $count): string
    {
        if (locale() === 'ar') {
            return '، ';
        }
        return $i === $count - 1 ? ($count > 2 ? ', and ' : ' and ') : ', ';
    }
}

if (!function_exists('brand_name')) {
    /** Official site name for the current (or given) locale: مُعِين خيري / MAURO. */
    function brand_name(?string $locale = null): string
    {
        $brand = (array) Config::get('app.brand', []);
        $locale ??= Translator::locale();
        return (string) ($brand[$locale] ?? $brand['en'] ?? Config::get('app.name', 'MAURO'));
    }
}

if (!function_exists('brand_logo')) {
    /**
     * The official logo file if it has been provided, else null.
     * @return array{url:string,width:int|null,height:int|null}|null
     */
    function brand_logo(): ?array
    {
        static $cache = false;
        if ($cache !== false) {
            return $cache;
        }
        $dir = (string) Config::get('app.logo_dir', 'assets/images/brand');
        $public = (string) Config::get('app.public_path', BASE_PATH . '/public');
        foreach (['svg', 'webp', 'png'] as $ext) {
            $file = "$public/$dir/logo.$ext";
            if (!is_file($file)) {
                continue;
            }
            $w = $h = null;
            if ($ext === 'svg') {
                $svg = (string) file_get_contents($file, false, null, 0, 4096);
                if (preg_match('/viewBox="\\s*[\\d.\\-]+[\\s,]+[\\d.\\-]+[\\s,]+([\\d.]+)[\\s,]+([\\d.]+)/', $svg, $m)) {
                    [$w, $h] = [(int) round((float) $m[1]), (int) round((float) $m[2])];
                }
            } elseif ($size = @getimagesize($file)) {
                [$w, $h] = [$size[0], $size[1]];
            }
            return $cache = ['url' => asset("$dir/logo.$ext"), 'width' => $w ?: null, 'height' => $h ?: null];
        }
        return $cache = null;
    }
}

if (!function_exists('old')) {
    /** Previously submitted value after a failed validation redirect. */
    function old(string $key, mixed $default = null): mixed
    {
        $old = \App\Core\Session::getFlash('old', []);
        return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
    }
}

if (!function_exists('field_error')) {
    function field_error(string $key): ?string
    {
        $errors = \App\Core\Session::getFlash('errors', []);
        return is_array($errors) && isset($errors[$key]) ? (string) $errors[$key] : null;
    }
}

if (!function_exists('flash')) {
    function flash(string $key): mixed
    {
        return \App\Core\Session::getFlash($key);
    }
}

if (!function_exists('app_key')) {
    /** Raw APP_KEY bytes (accepts "base64:…"). Used for HMAC signing; never output. */
    function app_key(): string
    {
        $key = (string) Config::get('app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }
        if (strlen($key) < 32) {
            throw new RuntimeException('APP_KEY is missing or too short. Run: php bin/console key:generate');
        }
        return $key;
    }
}
