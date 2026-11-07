<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\LibraryRepositoryFactory;
use App\Repositories\MySqlLibraryRepository;
use App\Services\AdminPasswordAuth;
use Tests\Support\LibraryFixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Administrator username/password sign-in through the real Kernel (router, CSRF/Origin middleware,
 * auth + admin middleware, controllers, views) on the SQLite harness.
 */
final class AdminPasswordLoginTest extends TestCase
{
    private const TEMP = 'Temporary-Pass-2026!';
    private const CHOSEN = 'my private passphrase 7';
    private array $ids = [];
    private mixed $demo;
    private int $adminId = 0;

    public function setUp(): void
    {
        TestDatabase::sqlite();
        $this->ids = LibraryFixtures::load();
        $this->demo = Config::get('ui.demo');
        Config::set('ui.demo', false);
        LibraryRepositoryFactory::set(new MySqlLibraryRepository(static fn () => LibraryFixtures::NOW));
        $_SESSION = [];
        $this->adminId = AdminPasswordAuth::createAdmin('Moein khairy', 'Moein.Khairy', self::TEMP);
    }

    public function tearDown(): void
    {
        Config::set('ui.demo', $this->demo);
        Kernel::resetRouter();
        $_SESSION = [];
        TestDatabase::release();
    }

    private function send(string $method, string $uri, array $post = [], bool $withToken = true, array $headers = []): Response
    {
        if ($method !== 'GET' && $withToken) {
            $post['_token'] = Csrf::token();
        }
        Session::resetForNextRequest();
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'] + $headers;
        return (new Kernel())->handle(new Request($query, $post, $server, ['dl_locale' => 'en']));
    }

    private function login(string $user, string $pass): Response
    {
        return $this->send('POST', '/admin/login', ['username' => $user, 'password' => $pass, 'redirect' => '/admin/books']);
    }

    private function logCount(string $action): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM admin_activity_logs WHERE action = :a AND admin_id = :id', ['a' => $action, 'id' => $this->adminId]);
    }

    public function testAccountIsStoredSafely(): void
    {
        $row = Database::first('SELECT * FROM users WHERE id = :id', ['id' => $this->adminId]);
        $this->assertSame('moein.khairy', $row['username'], 'username normalised to lowercase');
        $this->assertSame('Moein khairy', $row['name']);
        $this->assertSame('admin', $row['role']);
        $this->assertSame(1, (int) $row['password_must_change']);
        $this->assertTrue(str_starts_with($row['password_hash'], '$argon2id$') || str_starts_with($row['password_hash'], '$2y$'), 'Argon2id/bcrypt hash');
        $this->assertNotContains(self::TEMP, json_encode($row), 'no plaintext anywhere in the row');
        $this->assertSame('local:moein.khairy', $row['google_sub'], 'reserved placeholder, can never match a Google subject');
        $this->assertSame('moein.khairy@admin.invalid', $row['email']);
        $this->assertSame(1, $this->logCount('admin.created'));
        $this->assertThrows(\RuntimeException::class, fn () => AdminPasswordAuth::createAdmin('Other', 'moein.khairy', 'another long passphrase'));
        $this->assertThrows(\InvalidArgumentException::class, fn () => AdminPasswordAuth::createAdmin('Weak', 'weak.user', 'short'));
    }

    public function testFullFirstLoginFlow(): void
    {
        // Guests are sent to the admin sign-in page.
        $r = $this->send('GET', '/admin');
        $this->assertSame('/admin/login?redirect=%2Fadmin', $r->getHeader('Location'));
        $page = $this->send('GET', '/admin/login');
        $this->assertSame(200, $page->status());
        $this->assertContains('name="username"', $page->body());
        $this->assertContains('type="password"', $page->body());
        $this->assertContains('data-password-toggle', $page->body());
        $this->assertContains('noindex', $page->body());

        // Temporary password works once, but only the password page is reachable.
        $r = $this->login('moein.khairy', self::TEMP);
        $this->assertSame(303, $r->status());
        $this->assertSame('/admin/password', $r->getHeader('Location'));
        $this->assertSame($this->adminId, $_SESSION['user_id'] ?? null);
        $this->assertSame('/admin/password', $this->send('GET', '/admin')->getHeader('Location'));
        $this->assertSame('/admin/password', $this->send('GET', '/admin/books')->getHeader('Location'));
        $this->assertSame(200, $this->send('GET', '/admin/password')->status());

        // Validation of the new password.
        $bad = [
            ['wrong current password!!', self::CHOSEN, self::CHOSEN, 'current password is incorrect'],
            [self::TEMP, self::CHOSEN, self::CHOSEN . 'x', 'do not match'],
            [self::TEMP, 'short', 'short', 'at least 12'],
            [self::TEMP, 'xx moein.khairy xx', 'xx moein.khairy xx', 'must not contain the username'],
            [self::TEMP, self::TEMP, self::TEMP, 'must be different'],
        ];
        foreach ($bad as [$cur, $new, $conf, $msg]) {
            $r = $this->send('POST', '/admin/password', ['current_password' => $cur, 'new_password' => $new, 'new_password_confirmation' => $conf]);
            $this->assertSame(422, $r->status(), $msg);
            $this->assertContains($msg, $r->body());
        }
        $r = $this->send('POST', '/admin/password', ['current_password' => self::TEMP, 'new_password' => self::CHOSEN, 'new_password_confirmation' => self::CHOSEN]);
        $this->assertSame('/admin', $r->getHeader('Location'));
        $this->assertSame(0, (int) Database::scalar('SELECT password_must_change FROM users WHERE id = :id', ['id' => $this->adminId]));
        $this->assertSame(200, $this->send('GET', '/admin')->status(), 'dashboard open after the change');
        $this->assertSame(1, $this->logCount('admin.password_changed'));

        // Logout ends the session and is audited.
        $r = $this->send('POST', '/logout');
        $this->assertSame(303, $r->status());
        $this->assertSame(null, $_SESSION['user_id'] ?? null);
        $this->assertSame(1, $this->logCount('admin.logout'));
        $this->assertSame('/admin/login?redirect=%2Fadmin', $this->send('GET', '/admin')->getHeader('Location'));

        // Old temporary password is dead; the chosen one goes straight to the requested admin page.
        $this->assertSame(422, $this->login('moein.khairy', self::TEMP)->status());
        $r = $this->login('MOEIN.KHAIRY', self::CHOSEN);
        $this->assertSame('/admin/books', $r->getHeader('Location'), 'username is case-insensitive; redirect kept');
        $this->assertSame(200, $this->send('GET', '/admin/books')->status());
        $this->assertTrue($this->logCount('admin.login') === 2);
    }

    public function testGenericErrorsAndNoEnumeration(): void
    {
        $unknown = $this->login('nobody.here', 'whatever password 1');
        $wrong = $this->login('moein.khairy', 'whatever password 1');
        $this->assertSame(422, $unknown->status());
        $this->assertSame(422, $wrong->status());
        $this->assertContains('The username or password is incorrect.', $unknown->body());
        $this->assertContains('The username or password is incorrect.', $wrong->body());
        $this->assertNotContains('whatever password 1', $wrong->body(), 'password never echoed back');
        $this->assertSame(1, $this->logCount('admin.login_failed'));
        $this->assertSame(null, $_SESSION['user_id'] ?? null);
    }

    public function testCsrfAndOriginAreEnforced(): void
    {
        $r = $this->send('POST', '/admin/login', ['username' => 'moein.khairy', 'password' => self::TEMP], false);
        $this->assertSame(419, $r->status(), 'missing CSRF token');
        $r = $this->send('POST', '/admin/login', ['username' => 'moein.khairy', 'password' => self::TEMP], true, ['HTTP_ORIGIN' => 'https://evil.example']);
        $this->assertSame(403, $r->status(), 'foreign Origin');
        $this->assertSame(null, $_SESSION['user_id'] ?? null);
    }

    public function testBruteForceIsThrottledPerUsername(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(422, $this->login('moein.khairy', "wrong password number $i")->status());
        }
        $r = $this->login('moein.khairy', self::TEMP);
        $this->assertSame(429, $r->status(), 'even the right password is refused while throttled');
        $this->assertContains('Too many sign-in attempts', $r->body());
        $this->assertSame('900', $r->getHeader('Retry-After'));
        $this->assertSame(null, $_SESSION['user_id'] ?? null);

        // An operator reset clears the per-username throttle.
        AdminPasswordAuth::resetPassword('moein.khairy', 'Fresh-Temporary-2026');
        $this->assertSame('/admin/password', $this->login('moein.khairy', 'Fresh-Temporary-2026')->getHeader('Location'));
        $this->assertSame(1, $this->logCount('admin.password_reset'));
    }

    public function testOnlyActiveAdministratorsWithAPasswordCanSignIn(): void
    {
        // A normal member cannot use the admin sign-in nor reach the dashboard.
        $member = $this->ids['u_member'];
        Database::query('UPDATE users SET username = :u, password_hash = :h WHERE id = :id', ['u' => 'member.one', 'h' => AdminPasswordAuth::hash('member password 123'), 'id' => $member]);
        $this->assertSame(422, $this->login('member.one', 'member password 123')->status());
        $_SESSION['user_id'] = $member;
        $this->assertSame(403, $this->send('GET', '/admin')->status());
        $this->assertSame(403, $this->send('GET', '/admin/password')->status());
        $_SESSION = [];

        // Suspended administrator: refused, and an existing session dies immediately.
        Database::query("UPDATE users SET status = 'suspended' WHERE id = :id", ['id' => $this->adminId]);
        $this->assertSame(422, $this->login('moein.khairy', self::TEMP)->status());
        Database::query("UPDATE users SET status = 'active' WHERE id = :id", ['id' => $this->adminId]);
        $this->login('moein.khairy', self::TEMP);
        Database::query("UPDATE users SET status = 'suspended' WHERE id = :id", ['id' => $this->adminId]);
        $this->assertSame('/admin/login?redirect=%2Fadmin', $this->send('GET', '/admin')->getHeader('Location'));

        // Demoted: password sign-in refused.
        Database::query("UPDATE users SET status = 'active', role = 'user' WHERE id = :id", ['id' => $this->adminId]);
        $this->assertSame(422, $this->login('moein.khairy', self::TEMP)->status());
    }

    public function testPasswordSessionsExpire(): void
    {
        $this->login('moein.khairy', self::TEMP);
        $this->send('POST', '/admin/password', ['current_password' => self::TEMP, 'new_password' => self::CHOSEN, 'new_password_confirmation' => self::CHOSEN]);
        $this->assertSame(200, $this->send('GET', '/admin')->status());
        $_SESSION['auth_at'] = time() - (int) Config::get('security.admin_session_max') - 1;
        $r = $this->send('GET', '/admin/books');
        $this->assertSame('/admin/login?redirect=%2Fadmin%2Fbooks', $r->getHeader('Location'));
        $this->assertSame(null, $_SESSION['user_id'] ?? null, 'expired session is ended, not just redirected');
    }

    public function testGoogleAdministratorsAreUnaffected(): void
    {
        $_SESSION['user_id'] = $this->ids['u_admin'];     // Google-only admin from the fixtures (no password)
        $this->assertSame(200, $this->send('GET', '/admin')->status());
        $this->assertContains('signs in with Google', $this->send('GET', '/admin/password')->body());
    }
}
