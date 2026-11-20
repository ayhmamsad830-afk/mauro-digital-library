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
use App\Services\MemberPasswordAuth;
use App\Support\Publisher;
use Tests\Support\LibraryFixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/** Member settings (A4), publisher pages (B6) and SEO metadata (A3). */
final class AccountSettingsAndPublishersTest extends TestCase
{
    private const PASS = 'reading-is-fun 2026';
    private array $ids = [];
    private mixed $demo;

    public function setUp(): void
    {
        TestDatabase::sqlite();
        $this->ids = LibraryFixtures::load();
        $this->demo = Config::get('ui.demo');
        Config::set('ui.demo', false);
        Config::set('app.url', 'https://library.example.invalid');
        LibraryRepositoryFactory::set(new MySqlLibraryRepository(static fn () => LibraryFixtures::NOW));
        Kernel::resetRouter();
        $_SESSION = [];
        Auth::reset();
    }

    public function tearDown(): void
    {
        Config::set('ui.demo', $this->demo);
        LibraryRepositoryFactory::set(null);
        Kernel::resetRouter();
        $_SESSION = [];
        Auth::reset();
        TestDatabase::release();
    }

    private function send(string $method, string $uri, array $post = [], ?int $userId = null): Response
    {
        if ($userId !== null) {
            $_SESSION['user_id'] = $userId;
        } else {
            unset($_SESSION['user_id']);
        }
        if ($method !== 'GET') {
            $post['_token'] = Csrf::token();
        }
        Session::resetForNextRequest();
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'];
        return (new Kernel())->handle(new Request($query, $post, $server, ['dl_locale' => 'en']));
    }

    public function testSettingsRequireSignInAndShowTheAccount(): void
    {
        $this->assertSame(302, $this->send('GET', '/account/settings')->status());
        $r = $this->send('GET', '/account/settings', [], $this->ids['u_member']);
        $this->assertSame(200, $r->status());
        $this->assertContains(__('settings.profile'), $r->body());
        $this->assertContains(__('settings.google_only'), $r->body(), 'fixture members sign in with Google');
    }

    public function testProfileAndPreferencesPersistForThisUserOnly(): void
    {
        $member = $this->ids['u_member'];
        $other = $this->ids['u_admin'];
        $before = Database::first('SELECT name, preferred_locale, preferred_theme FROM users WHERE id = :id', ['id' => $other]);
        $r = $this->send('POST', '/account/settings/profile', ['name' => 'Member Renamed', 'preferred_locale' => 'ar', 'preferred_theme' => 'light', 'user_id' => $other, 'id' => $other], $member);
        $this->assertSame(303, $r->status());
        $row = Database::first('SELECT name, preferred_locale, preferred_theme FROM users WHERE id = :id', ['id' => $member]);
        $this->assertSame(['name' => 'Member Renamed', 'preferred_locale' => 'ar', 'preferred_theme' => 'light'], $row);
        $this->assertSame($before, Database::first('SELECT name, preferred_locale, preferred_theme FROM users WHERE id = :id', ['id' => $other]), 'ids in the form are ignored');

        $bad = $this->send('POST', '/account/settings/profile', ['name' => 'x', 'preferred_locale' => 'fr', 'preferred_theme' => 'neon'], $member);
        $this->assertSame(422, $bad->status());
        $this->assertSame('Member Renamed', (string) Database::scalar('SELECT name FROM users WHERE id = :id', ['id' => $member]));
    }

    public function testPasswordChangeKeepsThisSessionAndEndsOthers(): void
    {
        $id = (int) MemberPasswordAuth::register('Salma Reader', 'salma@example.invalid.test', self::PASS, self::PASS, '10.3.3.3')['user_id'];
        $wrong = $this->send('POST', '/account/settings/password', ['current_password' => 'nope nope nope', 'new_password' => 'a new long pass 1', 'new_password_confirmation' => 'a new long pass 1'], $id);
        $this->assertSame(422, $wrong->status());
        $this->assertContains(__('settings.current_wrong'), $wrong->body());

        $_SESSION['pw_seen'] = time() - 60;
        $ok = $this->send('POST', '/account/settings/password', ['current_password' => self::PASS, 'new_password' => 'a new long pass 1', 'new_password_confirmation' => 'a new long pass 1'], $id);
        $this->assertSame(303, $ok->status());
        Auth::reset();
        $this->assertSame($id, Auth::id(), 'the device that changed the password stays signed in');
        $this->assertTrue(MemberPasswordAuth::attempt('salma@example.invalid.test', 'a new long pass 1', '10.3.3.4')['ok']);

        $_SESSION = ['user_id' => $id, 'pw_seen' => time() - 60];
        Auth::reset();
        $this->assertSame(null, Auth::user(), 'another, older session is signed out');
    }

    public function testPublisherPagesUseRealDataWithStableSlugs(): void
    {
        $this->assertSame(Publisher::slug('صفقات كتابية للنشر والتوزيع'), Publisher::slug('  صفقات   كتابية للنشر والتوزيع '), 'spacing does not change the slug');
        $this->assertSame(1, preg_match('/^p-[0-9a-f]{10}$/', Publisher::slug('صفقات كتابية للنشر والتوزيع')), 'Arabic names: hash slug, same with or without ext-intl');
        $this->assertSame('dar-al-kutub', Publisher::slug('Dar Al Kutub'));

        Database::query("UPDATE books SET publisher = 'دار المعرفة' WHERE id = :a", ['a' => $this->ids['b1']]);
        Database::query("UPDATE books SET publisher = 'دار  المعرفة' WHERE id = :b", ['b' => $this->ids['b2']]);
        Database::query("UPDATE books SET publisher = 'Hidden Press' WHERE id = :c", ['c' => $this->ids['b5']]); // draft: invisible

        $list = $this->send('GET', '/publishers');
        $this->assertSame(200, $list->status());
        $this->assertContains('دار المعرفة', $list->body());
        $this->assertNotContains('Hidden Press', $list->body(), 'publishers of invisible books are not listed');

        $slug = Publisher::slug('دار المعرفة');
        $page = $this->send('GET', '/publishers/' . $slug);
        $this->assertSame(200, $page->status());
        $this->assertSame(2, substr_count($page->body(), 'class="book-card"'), 'both spellings, one publisher');
        $this->assertSame(404, $this->send('GET', '/publishers/' . Publisher::slug('Hidden Press'))->status());
        $this->assertSame(404, $this->send('GET', '/publishers/p-0000000000')->status());

        $book = $this->send('GET', '/books/' . Database::scalar('SELECT slug FROM books WHERE id = :id', ['id' => $this->ids['b1']]));
        $this->assertContains('/publishers/' . $slug, $book->body(), 'the book page links to its publisher');
        $this->assertContains('/publishers/' . $slug, $this->send('GET', '/sitemap.xml')->body());
    }

    public function testSeoMetadataOnPublicPages(): void
    {
        $slug = (string) Database::scalar('SELECT slug FROM books WHERE id = :id', ['id' => $this->ids['b1']]);
        $body = $this->send('GET', '/books/' . $slug)->body();
        $this->assertContains('<link rel="canonical" href="https://library.example.invalid/books/' . $slug . '">', $body);
        $this->assertContains('<meta property="og:type" content="book">', $body);
        $this->assertContains('<meta property="og:title"', $body);
        $this->assertContains('itemtype="https://schema.org/Book"', $body);
        $this->assertContains('itemprop="name"', $body);
        $this->assertFalse((bool) preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $body), 'structured data without inline script (strict CSP)');
        $home = $this->send('GET', '/')->body();
        $this->assertContains('<meta property="og:site_name"', $home);
        $this->assertContains('<link rel="canonical" href="https://library.example.invalid/">', $home);
    }
}
