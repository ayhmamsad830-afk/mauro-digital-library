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
use App\Services\Auth;
use App\Services\Mail\Mailer;
use App\Services\MemberPasswordAuth;
use App\Services\PasswordResetService;
use Tests\Support\LibraryFixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Member password recovery (B2): hashed single-use expiring tokens, no account enumeration,
 * rate limits, honest "unavailable" while e-mail is disabled, other sessions signed out.
 * E-mail never leaves the test: a fake transport records the messages.
 */
final class PasswordResetTest extends TestCase
{
    private const PASS = 'reading-is-fun 2026';
    private const NEW = 'another fine book 77';
    /** @var list<array{to:string,subject:string,text:string}> */
    private array $mails = [];
    private mixed $demo;
    private int $member;

    public function setUp(): void
    {
        TestDatabase::sqlite();
        LibraryFixtures::load();
        $this->demo = Config::get('ui.demo');
        Config::set('ui.demo', false);
        Config::set('app.url', 'https://library.example.invalid');
        LibraryRepositoryFactory::set(new MySqlLibraryRepository(static fn () => LibraryFixtures::NOW));
        Kernel::resetRouter();
        $_SESSION = [];
        Auth::reset();
        $this->member = (int) MemberPasswordAuth::register('Layla Haddad', 'layla@example.invalid.test', self::PASS, self::PASS, '10.0.0.1')['user_id'];
        $this->mails = [];
        Mailer::useTransport(function (string $to, string $subject, string $text): void {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
        });
    }

    public function tearDown(): void
    {
        Mailer::useTransport(null);
        Config::set('ui.demo', $this->demo);
        LibraryRepositoryFactory::set(null);
        Kernel::resetRouter();
        $_SESSION = [];
        Auth::reset();
        TestDatabase::release();
    }

    private function send(string $method, string $uri, array $post = [], string $ip = '127.0.0.1'): Response
    {
        if ($method !== 'GET') {
            $post['_token'] = Csrf::token();
        }
        Session::resetForNextRequest();
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => $ip, 'HTTP_HOST' => 'localhost'];
        return (new Kernel())->handle(new Request($query, $post, $server, ['dl_locale' => 'en']));
    }

    private function tokenFromMail(): string
    {
        $this->assertTrue($this->mails !== [], 'a mail was sent');
        preg_match('/token=([A-Za-z0-9_-]+)/', end($this->mails)['text'], $m);
        $this->assertTrue(isset($m[1]), 'the mail contains the reset link');
        return rawurldecode($m[1]);
    }

    public function testTokenIsHashedSingleUseAndTheMailHasTheLink(): void
    {
        $r = $this->send('POST', '/password/forgot', ['email' => ' LAYLA@example.invalid.test ']);
        $this->assertSame(200, $r->status());
        $this->assertContains(__('reset.sent', ['minutes' => PasswordResetService::TTL_MINUTES]), $r->body());
        $token = $this->tokenFromMail();
        $this->assertSame('layla@example.invalid.test', $this->mails[0]['to']);
        $this->assertContains('https://library.example.invalid/password/reset?token=', $this->mails[0]['text']);
        $stored = (string) Database::scalar('SELECT token_hash FROM password_resets WHERE user_id = :u', ['u' => $this->member]);
        $this->assertSame(hash('sha256', $token), $stored, 'only the hash is stored');
        $this->assertSame(0, (int) Database::scalar('SELECT COUNT(*) FROM password_resets WHERE token_hash = :t', ['t' => $token]), 'never the token itself');

        // The link puts the token in the session and redirects to a clean URL (no token in history/Referer).
        $open = $this->send('GET', '/password/reset?token=' . rawurlencode($token));
        $this->assertSame(303, $open->status());
        $this->assertSame('/password/reset', $open->getHeader('Location'));
        $form = $this->send('GET', '/password/reset');
        $this->assertContains('name="password_confirmation"', $form->body());

        $done = $this->send('POST', '/password/reset', ['password' => self::NEW, 'password_confirmation' => self::NEW]);
        $this->assertSame(303, $done->status());
        $this->assertSame('/login', $done->getHeader('Location'));
        $this->assertTrue(MemberPasswordAuth::attempt('layla@example.invalid.test', self::NEW, '10.0.0.9')['ok'], 'new password works');
        $this->assertFalse(MemberPasswordAuth::attempt('layla@example.invalid.test', self::PASS, '10.0.0.9')['ok'], 'old password refused');

        // Reuse is refused.
        $this->assertSame('reset.invalid_link', PasswordResetService::complete($token, 'yet another pass 99', 'yet another pass 99', '1.1.1.1'));
        $again = $this->send('GET', '/password/reset?token=' . rawurlencode($token));
        $this->assertSame(410, $again->status());
    }

    public function testNoEnumerationAndAdminsAreExcluded(): void
    {
        $known = $this->send('POST', '/password/forgot', ['email' => 'layla@example.invalid.test'])->body();
        $unknown = $this->send('POST', '/password/forgot', ['email' => 'nobody@example.invalid.test'])->body();
        $admin = $this->send('POST', '/password/forgot', ['email' => 'admin@example.invalid'])->body();
        $msg = __('reset.sent', ['minutes' => PasswordResetService::TTL_MINUTES]);
        foreach ([$known, $unknown, $admin] as $body) {
            $this->assertContains($msg, $body);
        }
        $this->assertSame(1, count($this->mails), 'only the real member received a mail');
        $this->assertSame(0, (int) Database::scalar("SELECT COUNT(*) FROM password_resets r JOIN users u ON u.id = r.user_id WHERE u.role = 'admin'"));
    }

    public function testExpiredTokensAndPolicyAreEnforced(): void
    {
        $this->send('POST', '/password/forgot', ['email' => 'layla@example.invalid.test']);
        $token = $this->tokenFromMail();
        $this->assertSame('member_auth.password_length', PasswordResetService::complete($token, 'short', 'short', '1.1.1.1'));
        $this->assertSame('member_auth.password_mismatch', PasswordResetService::complete($token, self::NEW, self::NEW . 'x', '1.1.1.1'));
        $this->assertSame('member_auth.password_is_email', PasswordResetService::complete($token, 'layla@example.invalid.test', 'layla@example.invalid.test', '1.1.1.1'));
        Database::query('UPDATE password_resets SET expires_at = :x', ['x' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $this->assertSame('reset.invalid_link', PasswordResetService::complete($token, self::NEW, self::NEW, '1.1.1.1'));
        $this->assertSame(null, PasswordResetService::find($token));
        $this->assertSame(null, PasswordResetService::find('../../etc/passwd'));
    }

    public function testANewRequestRevokesThePreviousLink(): void
    {
        $this->send('POST', '/password/forgot', ['email' => 'layla@example.invalid.test']);
        $first = $this->tokenFromMail();
        $this->send('POST', '/password/forgot', ['email' => 'layla@example.invalid.test']);
        $second = $this->tokenFromMail();
        $this->assertFalse($first === $second);
        $this->assertSame(null, PasswordResetService::find($first), 'older link revoked');
        $this->assertTrue(PasswordResetService::find($second) !== null);
    }

    public function testRateLimitsPerAddressAndPerIp(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->send('POST', '/password/forgot', ['email' => 'layla@example.invalid.test'], '10.1.1.1')->status());
        }
        $limited = $this->send('POST', '/password/forgot', ['email' => 'layla@example.invalid.test'], '10.1.1.2');
        $this->assertSame(429, $limited->status(), 'per-address limit (3/hour), whatever the IP');
        $this->assertSame(3, count($this->mails));
        for ($i = 0; $i < 10; $i++) {
            $this->send('POST', '/password/forgot', ['email' => "x$i@example.invalid.test"], '10.9.9.9');
        }
        $this->assertSame(429, $this->send('POST', '/password/forgot', ['email' => 'y@example.invalid.test'], '10.9.9.9')->status(), 'per-IP limit (10/hour)');
    }

    public function testUnavailableWhileEmailIsDisabled(): void
    {
        Mailer::useTransport(null);
        Config::set('mail.driver', 'disabled');
        $page = $this->send('GET', '/password/forgot');
        $this->assertContains(__('reset.unavailable'), $page->body());
        $this->assertNotContains('name="email"', $page->body(), 'no form that would pretend to send');
        $post = $this->send('POST', '/password/forgot', ['email' => 'layla@example.invalid.test']);
        $this->assertContains(__('reset.unavailable'), $post->body());
        $this->assertSame(0, (int) Database::scalar('SELECT COUNT(*) FROM password_resets'), 'no token without delivery');
    }

    public function testResetSignsOutOtherSessions(): void
    {
        $this->assertTrue(MemberPasswordAuth::attempt('layla@example.invalid.test', self::PASS, '10.0.0.5')['ok']);
        Auth::login($this->member);
        $this->assertSame($this->member, Auth::id(), 'signed in');
        $_SESSION['pw_seen'] = time() - 120; // this session opened two minutes ago

        $this->send('POST', '/password/forgot', ['email' => 'layla@example.invalid.test']);
        $this->assertSame(null, PasswordResetService::complete($this->tokenFromMail(), self::NEW, self::NEW, '1.1.1.1'));

        $_SESSION = ['user_id' => $this->member, 'pw_seen' => time() - 120];
        Auth::reset();
        $this->assertSame(null, Auth::user(), 'a session opened before the reset is ended');
    }
}
