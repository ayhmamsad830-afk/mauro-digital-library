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
use App\Services\Google\GoogleAuthService;
use App\Services\Google\GoogleIdTokenVerifier;
use App\Services\Google\InvalidGoogleToken;
use App\Services\Google\JwksProvider;
use Tests\Support\LibraryFixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Google ID-token verification and account provisioning, using a locally
 * generated RSA key pair standing in for Google's JWKS. (The real Google
 * endpoint cannot be reached from the test environment; see the report.)
 */
final class GoogleSignInTest extends TestCase
{
    private static $key = null;
    private array $ids = [];
    private mixed $clientId;
    private mixed $hd;
    private const CLIENT = '1234567890-test.apps.googleusercontent.com';

    public function setUp(): void
    {
        TestDatabase::sqlite();
        $this->ids = LibraryFixtures::load();
        $this->clientId = Config::get('google.client_id');
        $this->hd = Config::get('google.allowed_hd');
        Config::set('google.client_id', self::CLIENT);
        Config::set('google.allowed_hd', []);
        self::$key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $_SESSION = [];
        TestJwks::$calls = 0;
    }

    public function tearDown(): void
    {
        Config::set('google.client_id', $this->clientId);
        Config::set('google.allowed_hd', $this->hd);
        Kernel::resetRouter();
        $_SESSION = [];
        TestDatabase::release();
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function jwks(): array
    {
        $d = openssl_pkey_get_details(self::$key)['rsa'];
        return ['test-kid' => ['kty' => 'RSA', 'n' => self::b64($d['n']), 'e' => self::b64($d['e']), 'alg' => 'RS256']];
    }

    private function token(array $claims = [], array $header = [], ?\OpenSSLAsymmetricKey $key = null): string
    {
        $now = time();
        $claims += ['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT, 'sub' => '1098765432101234567890', 'email' => 'reader@example.com',
            'email_verified' => true, 'name' => 'Test Reader', 'picture' => 'https://lh3.googleusercontent.com/a/abc', 'iat' => $now, 'exp' => $now + 3600,
            'nonce' => GoogleAuthService::nonce()];
        $header += ['alg' => 'RS256', 'kid' => 'test-kid', 'typ' => 'JWT'];
        $input = self::b64(json_encode($header)) . '.' . self::b64(json_encode(array_filter($claims, static fn ($v) => $v !== null)));
        openssl_sign($input, $sig, $key ?? self::$key, OPENSSL_ALGO_SHA256);
        return $input . '.' . self::b64($sig);
    }

    private function verifier(): GoogleIdTokenVerifier
    {
        return new GoogleIdTokenVerifier(new TestJwks());
    }

    private function reason(string $jwt): string
    {
        try {
            $this->verifier()->verify($jwt, GoogleAuthService::nonce());
            return 'accepted';
        } catch (InvalidGoogleToken $e) {
            return $e->getMessage();
        }
    }

    // ------------------------------------------------------------ verifier

    public function testValidTokenIsAccepted(): void
    {
        $id = $this->verifier()->verify($this->token(), GoogleAuthService::nonce());
        $this->assertSame('1098765432101234567890', $id['sub']);
        $this->assertSame('reader@example.com', $id['email']);
        $this->assertSame('https://lh3.googleusercontent.com/a/abc', $id['picture']);
        $this->assertSame('accepted', $this->reason($this->token(['iss' => 'accounts.google.com'])), 'both Google issuer spellings');
    }

    public function testEveryRejectionRule(): void
    {
        $now = time();
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        [$h, $p] = explode('.', $this->token());
        $forgedPayload = self::b64(json_encode(['sub' => 'attacker', 'aud' => self::CLIENT] + json_decode(GoogleIdTokenVerifier::b64($p), true)));
        $none = self::b64(json_encode(['alg' => 'none', 'kid' => 'test-kid'])) . '.' . $p . '.';
        $cases = [
            'bad_alg' => $this->token([], ['alg' => 'HS256']),
            'bad_alg ' => $none . 'c2lnbmF0dXJl',
            'malformed ' => $none,
            'unknown_key' => $this->token([], ['kid' => 'nope']),
            'bad_signature' => $this->token([], [], $other),
            'bad_signature ' => "$h.$forgedPayload." . explode('.', $this->token())[2],
            'bad_issuer' => $this->token(['iss' => 'https://evil.example']),
            'bad_audience' => $this->token(['aud' => 'someone-else.apps.googleusercontent.com']),
            'expired' => $this->token(['exp' => $now - 3600]),
            'bad_iat' => $this->token(['iat' => $now + 3600]),
            'bad_nonce' => $this->token(['nonce' => 'replayed-or-foreign']),
            'bad_nonce  ' => $this->token(['nonce' => null]),
            'email_unverified' => $this->token(['email_verified' => false]),
            'no_sub' => $this->token(['sub' => '']),
            'malformed' => 'not.a.jwt.at.all',
        ];
        foreach ($cases as $expected => $jwt) {
            $this->assertSame(trim($expected), $this->reason($jwt), "case $expected");
        }
        Config::set('google.allowed_hd', ['school.example']);
        $this->assertSame('domain_not_allowed', $this->reason($this->token()));
        $this->assertSame('accepted', $this->reason($this->token(['hd' => 'school.example'])));
        Config::set('google.client_id', '');
        $this->assertSame('not_configured', $this->reason($this->token()));
    }

    public function testUnknownKeyIdForcesOneJwksRefresh(): void
    {
        $this->reason($this->token([], ['kid' => 'rotated']));
        $this->assertSame(2, TestJwks::$calls, 'cached keys, then one forced refresh');
    }

    public function testUntrustedAvatarUrlsAreDropped(): void
    {
        $id = $this->verifier()->verify($this->token(['picture' => 'https://evil.example/x.png']), GoogleAuthService::nonce());
        $this->assertSame(null, $id['picture']);
    }

    // ---------------------------------------------------------- provisioning

    public function testFirstSignInCreatesMemberRotatesSessionAndConsumesNonce(): void
    {
        $csrfBefore = Csrf::token();
        $jwt = $this->token();
        $r = GoogleAuthService::signIn($jwt, '203.0.113.9', $this->verifier());
        $this->assertTrue($r['ok'] && $r['created']);
        $u = Database::first('SELECT * FROM users WHERE google_sub = :s', ['s' => '1098765432101234567890']);
        $this->assertSame(['user', 'active', 'reader@example.com', 'Test Reader'], [$u['role'], $u['status'], $u['email'], $u['name']]);
        $this->assertSame((int) $u['id'], $_SESSION['user_id']);
        $this->assertFalse($csrfBefore === Csrf::token(), 'CSRF token rotated with the new identity');
        $replay = GoogleAuthService::signIn($jwt, '203.0.113.9', $this->verifier());
        $this->assertSame('bad_nonce', $replay['reason'], 'the same ID token cannot be replayed');
        $audit = Database::all('SELECT outcome, reason, ip_hash FROM oauth_audit_events ORDER BY id');
        $this->assertSame(['success', 'failure'], array_column($audit, 'outcome'));
        $this->assertFalse(str_contains(json_encode($audit), '203.0.113.9'), 'IP stored only as a keyed hash');
        $this->assertFalse(str_contains(json_encode(Database::all('SELECT * FROM oauth_audit_events')), substr($jwt, 0, 30)), 'token never stored');
    }

    public function testReturningUserIsUpdatedAndSuspendedUserRefused(): void
    {
        Database::query("UPDATE users SET google_sub = '1098765432101234567890', email = 'old@example.com' WHERE id = :id", ['id' => $this->ids['u_member']]);
        $r = GoogleAuthService::signIn($this->token(['name' => 'New Name']), '1.1.1.1', $this->verifier());
        $this->assertTrue($r['ok'] && !$r['created']);
        $this->assertSame($this->ids['u_member'], $r['user_id']);
        $this->assertSame(['New Name', 'reader@example.com'], array_values(Database::first('SELECT name, email FROM users WHERE id = :id', ['id' => $this->ids['u_member']])));
        $this->assertTrue(Database::scalar('SELECT last_login_at FROM users WHERE id = :id', ['id' => $this->ids['u_member']]) !== null);

        $_SESSION = [];
        Database::query("UPDATE users SET status = 'suspended' WHERE id = :id", ['id' => $this->ids['u_member']]);
        $s = GoogleAuthService::signIn($this->token(), '1.1.1.1', $this->verifier());
        $this->assertSame('suspended', $s['reason']);
        $this->assertFalse(isset($_SESSION['user_id']));
    }

    public function testEmailBoundToAnotherGoogleAccountIsNotMerged(): void
    {
        $r = GoogleAuthService::signIn($this->token(['email' => 'member@example.invalid', 'sub' => 'different-sub']), '1.1.1.1', $this->verifier());
        $this->assertSame('email_conflict', $r['reason']);
        $this->assertSame(0, (int) Database::scalar("SELECT COUNT(*) FROM users WHERE google_sub = 'different-sub'"));
    }

    // ----------------------------------------------------------------- HTTP

    private function send(string $method, string $uri, array $json = [], bool $csrf = true): Response
    {
        Session::resetForNextRequest();
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($method !== 'GET' && $csrf) {
            $server['HTTP_X_CSRF_TOKEN'] = Csrf::token();
        }
        return (new Kernel())->handle(new Request([], [], $server, ['dl_locale' => 'en'], [], json_encode($json)));
    }

    public function testHttpEndpointIsCsrfProtectedRateLimitedAndRejectsBadTokens(): void
    {
        $this->assertSame(419, $this->send('POST', '/api/auth/google', ['credential' => 'x'], false)->status());
        $r = $this->send('POST', '/api/auth/google', ['credential' => 'x.y.z']);
        $this->assertSame(401, $r->status());
        $this->assertContains('Google sign-in failed', $r->body());
        for ($i = 0; $i < 4; $i++) {
            $this->send('POST', '/api/auth/google', ['credential' => 'x.y.z']);
        }
        $this->assertSame(429, $this->send('POST', '/api/auth/google', ['credential' => 'x.y.z'])->status(), '6th attempt in 15 min');
        Config::set('google.client_id', '');
        $this->assertSame(503, $this->send('POST', '/api/auth/google', ['credential' => 'x.y.z'])->status());
    }

    public function testLoginPageRendersGisOnlyWhenConfiguredAndLogoutEndsSession(): void
    {
        $page = $this->send('GET', '/login');
        $this->assertContains('data-client-id="' . self::CLIENT . '"', $page->body());
        $this->assertContains('data-nonce="', $page->body());
        $this->assertContains('auth.js', $page->body());
        $this->assertNotContains('client_secret', strtolower($page->body()));
        $_SESSION['user_id'] = $this->ids['u_member'];
        $this->assertSame(302, $this->send('GET', '/login')->status(), 'already signed in');
        $this->assertSame(419, $this->send('POST', '/logout', [], false)->status(), 'logout needs CSRF');
        $out = $this->send('POST', '/logout');
        $this->assertSame(303, $out->status());
        $this->assertFalse(isset($_SESSION['user_id']));
        $this->assertSame(401, $this->send('GET', '/account')->status(), 'account requires sign-in again (JSON client → 401)');
    }
}

final class TestJwks implements JwksProvider
{
    public static int $calls = 0;

    public function keys(bool $forceRefresh = false): array
    {
        self::$calls++;
        return GoogleSignInTest::jwks();
    }
}
