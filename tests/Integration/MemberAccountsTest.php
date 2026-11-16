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
use App\Demo\DemoLibraryRepository;
use App\Models\Setting;
use App\Repositories\LibraryRepositoryFactory;
use App\Repositories\MySqlLibraryRepository;
use App\Services\AdminPasswordAuth;
use App\Services\Auth;
use App\Services\Google\GoogleAuthService;
use App\Services\Google\GoogleIdTokenVerifier;
use App\Services\GuestPreview;
use App\Services\MemberPasswordAuth;
use Tests\Support\LibraryFixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Reader accounts with e-mail + password, the guest free preview (server-enforced), and linking an
 * e-mail account when its owner later signs in with Google. Real Kernel on the SQLite harness.
 */
final class MemberAccountsTest extends TestCase
{
    private const PASS = 'reading-is-fun 2026';
    private const DEMO_BOOK = 'the-moon-who-lost-her-shoe'; // 32 pages in the demo catalogue
    private mixed $demo;

    public function setUp(): void
    {
        TestDatabase::sqlite();
        LibraryFixtures::load();
        $this->demo = Config::get('ui.demo');
        Config::set('ui.demo', false);
        LibraryRepositoryFactory::set(new MySqlLibraryRepository(static fn () => LibraryFixtures::NOW));
        $_SESSION = [];
        Auth::reset();
    }

    public function tearDown(): void
    {
        Config::set('ui.demo', $this->demo);
        Kernel::resetRouter();
        $_SESSION = [];
        Auth::reset();
        TestDatabase::release();
    }

    private function send(string $method, string $uri, array $post = []): Response
    {
        if ($method !== 'GET') {
            $post['_token'] = Csrf::token();
        }
        Session::resetForNextRequest();
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'];
        return (new Kernel())->handle(new Request($query, $post, $server, ['dl_locale' => 'en']));
    }

    private function register(array $over = []): Response
    {
        return $this->send('POST', '/register', $over + [
            'name' => 'Layla Haddad', 'email' => ' Layla@Example.COM ', 'password' => self::PASS,
            'password_confirmation' => $over['password'] ?? self::PASS, 'redirect' => '/read/' . self::DEMO_BOOK,
        ]);
    }

    private function signOut(): void
    {
        $_SESSION = [];
        Auth::reset();
    }

    private function userCount(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM users');
    }

    public function testRegisterCreatesFreeMemberAndSignsIn(): void
    {
        $r = $this->register();
        $this->assertSame(303, $r->status());
        $this->assertSame('/read/' . self::DEMO_BOOK, $r->getHeader('Location'), 'back to the book being read');

        $row = Database::first('SELECT * FROM users WHERE email = :e', ['e' => 'layla@example.com']);
        $this->assertTrue(is_array($row), 'e-mail normalised to lowercase, trimmed');
        $this->assertSame('user', $row['role'], 'never an administrator');
        $this->assertSame('active', $row['status']);
        $this->assertTrue(str_starts_with((string) $row['google_sub'], MemberPasswordAuth::SUB_PREFIX));
        $this->assertSame(null, $row['email_verified_at'], 'address not verified without a mail service');
        $this->assertFalse(str_contains((string) $row['password_hash'], self::PASS), 'no plaintext');
        $this->assertTrue(password_verify(self::PASS, (string) $row['password_hash']));
        $this->assertSame((int) $row['id'], Session::get('user_id'), 'signed in right away');
    }

    public function testRegisterValidation(): void
    {
        $before = $this->userCount();
        $cases = [
            'bad e-mail' => ['email' => 'not-an-email'],
            'reserved domain' => ['email' => 'x@admin.invalid'],
            'short password' => ['password' => 'short'],
            'password = e-mail' => ['email' => 'same@example.com', 'password' => 'same@example.com'],
            'repetitive password' => ['password' => 'aaaaaaaaaa'],
            'mismatch' => ['password_confirmation' => 'something else entirely'],
            'no name' => ['name' => ' '],
        ];
        foreach ($cases as $label => $over) {
            $this->signOut();
            $r = $this->register($over);
            $this->assertSame(422, $r->status(), $label);
        }
        $this->assertSame($before, $this->userCount(), 'nothing created');

        $this->register();
        $this->signOut();
        $taken = $this->register(['email' => 'LAYLA@example.com']);
        $this->assertSame(422, $taken->status());
        $this->assertContains(__('member_auth.email_taken'), $taken->body());
    }

    public function testEmailLoginWithGenericErrors(): void
    {
        $this->register();
        $this->signOut();

        $wrong = $this->send('POST', '/login/email', ['email' => 'layla@example.com', 'password' => 'wrong password 1']);
        $unknown = $this->send('POST', '/login/email', ['email' => 'nobody@example.com', 'password' => self::PASS]);
        $this->assertSame(422, $wrong->status());
        $this->assertSame(422, $unknown->status());
        $this->assertContains(__('member_auth.invalid'), $wrong->body());
        $this->assertContains(__('member_auth.invalid'), $unknown->body(), 'same message: no account enumeration');
        $this->assertSame(null, Session::get('user_id'));

        $ok = $this->send('POST', '/login/email', ['email' => ' LAYLA@example.com', 'password' => self::PASS, 'redirect' => '/books']);
        $this->assertSame(303, $ok->status());
        $this->assertSame('/books', $ok->getHeader('Location'));
        $this->assertTrue(is_int(Session::get('user_id')));

        $this->signOut();
        $evil = $this->send('POST', '/login/email', ['email' => 'layla@example.com', 'password' => self::PASS, 'redirect' => 'https://evil.example/']);
        $this->assertSame('/account', $evil->getHeader('Location'), 'open redirect refused');
    }

    public function testSuspendedAndAdminAccountsCannotUseTheReaderForm(): void
    {
        $this->register();
        Database::query("UPDATE users SET status = 'suspended' WHERE email = 'layla@example.com'");
        $this->signOut();
        $r = $this->send('POST', '/login/email', ['email' => 'layla@example.com', 'password' => self::PASS]);
        $this->assertSame(422, $r->status());
        $this->assertSame(null, Session::get('user_id'));

        AdminPasswordAuth::createAdmin('Boss', 'boss', 'Temporary-Pass-2026!');
        $this->signOut();
        $admin = $this->send('POST', '/login/email', ['email' => 'boss@admin.invalid', 'password' => 'Temporary-Pass-2026!']);
        $this->assertSame(422, $admin->status(), 'administrators use /admin/login');
    }

    public function testLoginIsThrottledPerEmail(): void
    {
        $this->register();
        $this->signOut();
        $max = (int) Config::get('security.rate_limits.member_login_email.max');
        for ($i = 0; $i < $max; $i++) {
            $this->send('POST', '/login/email', ['email' => 'layla@example.com', 'password' => "wrong $i password"]);
        }
        $r = $this->send('POST', '/login/email', ['email' => 'layla@example.com', 'password' => self::PASS]);
        $this->assertSame(429, $r->status(), 'even the right password is refused while throttled');
        $this->assertSame(null, Session::get('user_id'));
    }

    public function testGuestPreviewLimitAndSetting(): void
    {
        $this->assertSame(GuestPreview::DEFAULT_PAGES, GuestPreview::limit());
        $this->assertTrue(GuestPreview::allows(10));
        $this->assertFalse(GuestPreview::allows(11));

        $admin = AdminPasswordAuth::createAdmin('Boss', 'boss', 'Temporary-Pass-2026!');
        Setting::set(GuestPreview::SETTING, '3', $admin);
        $this->assertTrue(GuestPreview::allows(3));
        $this->assertFalse(GuestPreview::allows(4));

        $this->register();
        Auth::reset();
        $this->assertSame(null, GuestPreview::limit(), 'members read everything');
        $this->assertTrue(GuestPreview::allows(400));
    }

    public function testPagesBeyondThePreviewAreRefusedByTheServer(): void
    {
        Config::set('ui.demo', true);
        Kernel::resetRouter();
        LibraryRepositoryFactory::set(new DemoLibraryRepository());
        $page = static fn (int $n) => '/api/demo/books/' . self::DEMO_BOOK . "/pages/$n";

        $this->assertSame(200, $this->send('GET', $page(10))->status(), 'last free page');
        $this->assertSame(403, $this->send('GET', $page(11))->status(), 'guest: page 11 refused');
        $this->assertSame(403, $this->send('GET', '/api/demo/books/' . self::DEMO_BOOK . '/thumbnails/11')->status(), 'thumbnails too');

        $shell = $this->send('GET', '/read/' . self::DEMO_BOOK)->body();
        $this->assertContains('data-gate', $shell, 'guest sees the free-account wall');
        $this->assertContains('&quot;previewPages&quot;:10', $shell);

        $this->register();
        Auth::reset();
        $this->assertSame(200, $this->send('GET', $page(11))->status(), 'member: whole book');
        $this->assertNotContains('data-gate', $this->send('GET', '/read/' . self::DEMO_BOOK)->body());
    }

    public function testGoogleSignInLinksAnEmailAccountAndDropsItsUnverifiedPassword(): void
    {
        $key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            $this->markSkipped('OpenSSL cannot generate an RSA key here (set OPENSSL_CONF)');
        }
        $client = '1234567890-test.apps.googleusercontent.com';
        $prevClient = Config::get('google.client_id');
        Config::set('google.client_id', $client);
        try {
            $this->register();
            $id = (int) Database::scalar("SELECT id FROM users WHERE email = 'layla@example.com'");
            $this->signOut();

            $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $d = openssl_pkey_get_details($key)['rsa'];
            $jwks = new class (['kid1' => ['kty' => 'RSA', 'n' => $b64($d['n']), 'e' => $b64($d['e']), 'alg' => 'RS256']]) implements \App\Services\Google\JwksProvider {
                public function __construct(private array $keys) {}
                public function keys(bool $forceRefresh = false): array { return $this->keys; }
            };
            $now = time();
            $claims = ['iss' => 'https://accounts.google.com', 'aud' => $client, 'sub' => '109876543210', 'email' => 'layla@example.com',
                'email_verified' => true, 'name' => 'Layla G', 'picture' => 'https://lh3.googleusercontent.com/a/x', 'iat' => $now, 'exp' => $now + 600,
                'nonce' => GoogleAuthService::nonce()];
            $input = $b64(json_encode(['alg' => 'RS256', 'kid' => 'kid1', 'typ' => 'JWT'])) . '.' . $b64(json_encode($claims));
            openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);

            $res = GoogleAuthService::signIn($input . '.' . $b64($sig), '127.0.0.1', new GoogleIdTokenVerifier($jwks));
            $this->assertTrue($res['ok'], 'linked instead of "e-mail conflict"');
            $this->assertSame($id, $res['user_id'], 'same account, no duplicate');
            $row = Database::first('SELECT google_sub, password_hash, email_verified_at, name FROM users WHERE id = :id', ['id' => $id]);
            $this->assertSame('109876543210', $row['google_sub']);
            $this->assertSame(null, $row['password_hash'], 'unverified password removed');
            $this->assertTrue($row['email_verified_at'] !== null);
            $this->assertSame('Layla Haddad', $row['name'], 'the name the reader chose is kept');
        } finally {
            Config::set('google.client_id', $prevClient);
        }
    }
}
