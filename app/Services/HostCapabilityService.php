<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use Throwable;

/**
 * Verifies that the current host can run the protected-reader architecture
 * (spec §14.1): PHP version/extensions, private storage, upload limits and —
 * most importantly — a real server-side PDF → image → WebP round-trip.
 *
 * Run on every candidate host BEFORE committing to it:  php bin/console env:check
 */
final class HostCapabilityService
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** @return list<array{group:string, check:string, status:string, detail:string}> */
    public function run(): array
    {
        $r = [];

        // ---------------- PHP
        $r[] = $this->item('PHP', 'Version', version_compare(PHP_VERSION, '8.4.0', '>=')
            ? self::OK : (version_compare(PHP_VERSION, '8.3.0', '>=') ? self::WARN : self::FAIL),
            PHP_VERSION . ' (target 8.4+, minimum 8.3)');

        foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'openssl', 'json', 'gd', 'curl', 'intl'] as $ext) {
            $required = !in_array($ext, ['intl'], true);
            $loaded = extension_loaded($ext);
            $r[] = $this->item('PHP', "ext-$ext", $loaded ? self::OK : ($required ? self::FAIL : self::WARN), $loaded ? 'loaded' : 'missing');
        }

        $gd = function_exists('gd_info') ? gd_info() : [];
        $r[] = $this->item('Images', 'GD WebP', !empty($gd['WebP Support']) ? self::OK : self::WARN,
            !empty($gd['WebP Support']) ? 'supported' : 'not supported — JPEG fallback will be used');
        $r[] = $this->item('Images', 'GD FreeType (watermark text)', !empty($gd['FreeType Support']) ? self::OK : self::WARN,
            !empty($gd['FreeType Support']) ? 'supported' : 'missing — built-in bitmap font fallback only');

        // ---------------- Limits
        $pdfMax = (int) Config::get('storage.pdf_max_bytes', 10485760);
        $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
        $post = self::iniBytes((string) ini_get('post_max_size'));
        $r[] = $this->item('Limits', 'upload_max_filesize', $upload >= $pdfMax ? self::OK : self::WARN,
            ini_get('upload_max_filesize') . ' (PDF limit ' . round($pdfMax / 1048576) . ' MB)');
        $r[] = $this->item('Limits', 'post_max_size', $post > $pdfMax ? self::OK : self::WARN, (string) ini_get('post_max_size'));
        $r[] = $this->item('Limits', 'memory_limit', self::memoryOk() ? self::OK : self::WARN, (string) ini_get('memory_limit') . ' (256M+ recommended for rendering)');

        // ---------------- Storage
        $publicPath = (string) Config::get('app.public_path', BASE_PATH . '/public');
        $public = realpath($publicPath) ?: $publicPath;
        foreach (['root', 'original_pdf', 'rendered_pages', 'thumbnails', 'covers', 'temp', 'logs', 'backups'] as $key) {
            $path = (string) Config::get("storage.$key");
            $real = realpath($path) ?: $path;
            if (str_starts_with($real . '/', $public . '/')) {
                $r[] = $this->item('Storage', $key, self::FAIL, 'INSIDE public document root: ' . $path);
                continue;
            }
            $ok = is_dir($path) && is_writable($path);
            $r[] = $this->item('Storage', $key, $ok ? self::OK : self::FAIL, $ok ? 'writable, outside docroot' : 'missing or not writable: ' . $path);
        }

        $free = @disk_free_space((string) Config::get('storage.root'));
        $minMb = (int) Config::get('rendering.min_free_disk_mb', 500);
        $r[] = $this->item('Storage', 'free disk space', $free === false ? self::WARN : ($free / 1048576 >= $minMb * 4 ? self::OK : ($free / 1048576 >= $minMb ? self::WARN : self::FAIL)),
            $free === false ? 'unknown' : round($free / 1048576) . " MB free (minimum $minMb MB, recommended " . ($minMb * 4) . ' MB)');
        $perms = @fileperms((string) Config::get('storage.original_pdf'));
        $r[] = $this->item('Storage', 'original-pdf permissions', $perms !== false && ($perms & 0007) === 0 ? self::OK : self::WARN,
            $perms === false ? 'unknown' : sprintf('%o (recommended 750: no access for "other")', $perms & 0777));

        $met = (int) ini_get('max_execution_time');
        $r[] = $this->item('Limits', 'max_execution_time', ($met === 0 || $met >= 120) ? self::OK : self::WARN,
            ($met === 0 ? 'unlimited' : $met . ' s') . ' (' . PHP_SAPI . '; web requests only need it for "process now"; the cron worker runs in CLI)');

        // ---------------- Process execution + renderer
        $proc = ProcessRunner::available();
        $r[] = $this->item('Rendering', 'proc_open', $proc ? self::OK : self::FAIL, $proc ? 'enabled' : 'disabled by host — server-side rendering impossible here');

        $engine = (string) Config::get('rendering.engine', 'poppler');
        $bins = $engine === 'mutool' ? ['mutool'] : ['pdftoppm', 'pdfinfo'];
        $paths = [];
        foreach ($bins as $bin) {
            $paths[$bin] = ProcessRunner::which((string) Config::get("rendering.binaries.$bin", $bin));
            $r[] = $this->item('Rendering', $bin, $paths[$bin] ? self::OK : self::FAIL, $paths[$bin] ?? 'not found in PATH');
        }

        if ($proc && !in_array(null, $paths, true) && $engine !== 'mutool') {
            $r[] = $this->renderRoundTrip((string) $paths['pdftoppm'], (string) $paths['pdfinfo']);
        } else {
            $r[] = $this->item('Rendering', 'PDF → image round-trip', self::FAIL, 'skipped (prerequisites missing)');
        }

        // ---------------- Configuration
        $key = (string) Config::get('app.key', '');
        $r[] = $this->item('Config', 'APP_KEY', strlen(base64_decode(preg_replace('/^base64:/', '', $key) ?: '', true) ?: '') >= 32 ? self::OK : self::FAIL,
            $key === '' ? 'empty — run: php bin/console key:generate' : 'set');
        $prod = Config::get('app.env') === 'production';
        $r[] = $this->item('Config', 'APP_DEBUG', ($prod && Config::get('app.debug')) ? self::FAIL : self::OK,
            (Config::get('app.debug') ? 'true' : 'false') . ' (env: ' . Config::get('app.env') . ')');
        $r[] = $this->item('Config', 'APP_URL https', str_starts_with((string) Config::get('app.url'), 'https://') ? self::OK : ($prod ? self::FAIL : self::WARN),
            (string) Config::get('app.url'));
        $r[] = $this->item('Config', 'GOOGLE_CLIENT_ID', Config::get('google.client_id') ? self::OK : self::WARN,
            Config::get('google.client_id') ? 'set' : 'not configured — Google Sign-In unavailable');
        array_push($r, ...$this->googleConnectivity((bool) Config::get('google.client_id')));
        $secure = Config::get('session.secure', 'auto');
        $r[] = $this->item('Config', 'SESSION_SECURE_COOKIE', $secure === true ? self::OK : ($prod ? self::WARN : self::OK),
            ($secure === 'auto' ? 'auto (Secure only on HTTPS requests)' : ($secure ? 'true' : 'false')) . ($prod && $secure !== true ? ' — set true in production' : ''));
        $r[] = $this->item('Config', 'Demo UI', Config::get('ui.demo') ? ($prod ? self::FAIL : self::WARN) : self::OK,
            Config::get('ui.demo') ? 'ON — fictional sample data is shown' : 'off');
        $sessions = (string) Config::get('session.save_path', '');
        $r[] = $this->item('Storage', 'sessions', is_dir($sessions) && is_writable($sessions) ? self::OK : self::FAIL, $sessions);
        $r[] = $this->item('PHP', 'display_errors', in_array(strtolower((string) ini_get('display_errors')), ['', '0', 'off', 'stderr'], true) || PHP_SAPI === 'cli' ? self::OK : ($prod ? self::FAIL : self::WARN),
            (string) ini_get('display_errors') . ' (must be Off on the web server in production)');
        $r[] = $this->item('PHP', 'expose_php', ini_get('expose_php') ? self::WARN : self::OK, ini_get('expose_php') ? 'On — recommended Off (the app also removes X-Powered-By)' : 'Off');

        // ---------------- Database
        if (Config::get('database.driver') === 'mysql' && (string) Config::get('database.username', '') === '') {
            $r[] = $this->item('Database', 'connection', $prod ? self::FAIL : self::WARN, 'DB_USERNAME empty — no database configured');
        } else {
            try {
                $pdo = \App\Core\Database::connection();
                $r[] = $this->item('Database', 'connection', self::OK, (string) Config::get('database.driver') . ' connected');
                if (Config::get('database.driver') === 'mysql') {
                    $m = new \App\Core\Migrator($pdo, BASE_PATH . '/database/migrations');
                    $pending = array_diff($m->files(), $m->applied());
                    $r[] = $this->item('Database', 'migrations', $pending === [] ? self::OK : self::FAIL, $pending === [] ? 'all applied' : count($pending) . ' pending — run: php bin/console migrate');
                }
            } catch (Throwable $e) {
                $r[] = $this->item('Database', 'connection', self::FAIL, 'cannot connect: ' . mb_substr(preg_replace('/password=\S+/i', 'password=***', $e->getMessage()) ?? '', 0, 160));
            }
        }
        $dump = ProcessRunner::available() ? ProcessRunner::which('mysqldump') : null;
        $r[] = $this->item('Backup', 'mysqldump', $dump ? self::OK : self::WARN, $dump ?? 'not found — use the host panel backups or install mysql-client');

        // Keep each group together (stable sort by first appearance).
        $order = array_flip(array_values(array_unique(array_column($r, 'group'))));
        usort($r, static fn (array $a, array $b): int => $order[$a['group']] <=> $order[$b['group']]);
        return $r;
    }

    /**
     * Live HTTPS request to Google's ID-token signing keys (JWKS) through the same
     * code path Google Sign-In uses, with certificate verification ON. Mandatory
     * (FAIL) only when Google Sign-In is configured. Prints no secrets: only the
     * CA file path, HTTP status, curl error text and the number of keys.
     *
     * @return list<array{group:string,check:string,status:string,detail:string}>
     */
    private function googleConnectivity(bool $googleConfigured): array
    {
        $bad = $googleConfigured ? self::FAIL : self::WARN;
        $ca = (string) ini_get('curl.cainfo');
        $caDetail = $ca === '' ? 'curl.cainfo not set (system/default CA store)'
            : (is_file($ca) ? "curl.cainfo = $ca" : "curl.cainfo = $ca — FILE NOT FOUND");
        $out = [$this->item('Network', 'CA certificates', $ca !== '' && !is_file($ca) ? $bad : self::OK, $caDetail)];

        if (!extension_loaded('curl')) {
            $out[] = $this->item('Network', 'Google JWKS (HTTPS)', $bad, 'skipped — ext-curl missing');
            return $out;
        }
        $res = \App\Services\Google\HttpJwksProvider::request();
        if ($res['status'] === 200 && $res['keys'] !== null && $res['keys'] !== []) {
            $out[] = $this->item('Network', 'Google JWKS (HTTPS)', self::OK, sprintf(
                'reachable, TLS certificate verified, %d signing key(s) (HTTP 200, %d ms)', count($res['keys']), $res['ms']));
            return $out;
        }
        if ($res['errno'] !== 0) {
            $hint = in_array($res['errno'], [60, 77], true)
                ? ' — PHP has no trusted CA bundle; set curl.cainfo (Windows dev: run via bin\\dev-php.ps1, see README)'
                : '';
            $detail = "curl {$res['errno']}: " . mb_substr($res['error'], 0, 140) . $hint;
        } else {
            $detail = "unexpected response (HTTP {$res['status']}, " . ($res['keys'] === null ? 'no key set' : '0 RSA keys') . ')';
        }
        $out[] = $this->item('Network', 'Google JWKS (HTTPS)', $bad,
            $detail . ($googleConfigured ? ' — Google Sign-In will reject every login' : ''));
        return $out;
    }

    /** Generate a 1-page PDF, rasterise it with Poppler, then encode it with GD. */
    private function renderRoundTrip(string $pdftoppm, string $pdfinfo): array
    {
        $tmpDir = (string) Config::get('storage.temp');
        $base = $tmpDir . '/capcheck_' . bin2hex(random_bytes(6));
        $pdf = $base . '.pdf';
        try {
            file_put_contents($pdf, self::samplePdf());
            $info = ProcessRunner::run([$pdfinfo, $pdf], 15);
            if ($info['exit'] !== 0 || !preg_match('/^Pages:\s+1\s*$/m', $info['stdout'])) {
                return $this->item('Rendering', 'PDF → image round-trip', self::FAIL, 'pdfinfo failed: ' . trim($info['stderr']));
            }
            $start = microtime(true);
            $res = ProcessRunner::run([$pdftoppm, '-png', '-r', '72', '-f', '1', '-l', '1', '-singlefile', $pdf, $base], 30);
            $png = $base . '.png';
            if ($res['exit'] !== 0 || !is_file($png)) {
                return $this->item('Rendering', 'PDF → image round-trip', self::FAIL, 'pdftoppm failed: ' . trim($res['stderr']));
            }
            $img = @imagecreatefrompng($png);
            if (!$img) {
                return $this->item('Rendering', 'PDF → image round-trip', self::FAIL, 'GD could not read the rendered PNG');
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $format = function_exists('imagewebp') ? 'webp' : 'jpeg';
            ob_start();
            $format === 'webp' ? imagewebp($img, null, 80) : imagejpeg($img, null, 80);
            $bytes = strlen((string) ob_get_clean());
            imagedestroy($img);
            @unlink($png);
            return $this->item('Rendering', 'PDF → image round-trip', self::OK,
                sprintf('%dx%d px → %s %d bytes in %.0f ms', $w, $h, $format, $bytes, (microtime(true) - $start) * 1000));
        } catch (Throwable $e) {
            return $this->item('Rendering', 'PDF → image round-trip', self::FAIL, $e->getMessage());
        } finally {
            @unlink($pdf);
        }
    }

    /** Smallest valid single-page PDF with a text object (xref offsets computed). */
    public static function samplePdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 100] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            null, // stream, built below
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $stream = "BT /F1 18 Tf 20 45 Td (Capability check) Tj ET";
        $objects[3] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";

        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }
        $out .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }

    private function item(string $group, string $check, string $status, string $detail): array
    {
        return ['group' => $group, 'check' => $check, 'status' => $status, 'detail' => $detail];
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $unit = strtolower(substr($value, -1));
        $num = (int) $value;
        return match ($unit) {
            'g' => $num * 1073741824,
            'm' => $num * 1048576,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }

    private static function memoryOk(): bool
    {
        return self::iniBytes((string) ini_get('memory_limit')) >= 256 * 1048576;
    }
}
