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
use App\Models\Setting;
use App\Repositories\LibraryRepositoryFactory;
use App\Repositories\MySqlLibraryRepository;
use App\Services\Auth;
use App\Services\BackupService;
use App\Services\Mail\Mailer;
use Tests\Support\LibraryFixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Contact form (B4/B7), About page (B3), admin Messages and read-only Backups screens (B5).
 * Messages are always stored; the notification status is never faked.
 */
final class ContactAndAdminScreensTest extends TestCase
{
    private array $ids = [];
    private mixed $demo;
    private ?string $backups = null;
    private array $sent = [];

    public function setUp(): void
    {
        TestDatabase::sqlite();
        $this->ids = LibraryFixtures::load();
        $this->demo = Config::get('ui.demo');
        Config::set('ui.demo', false);
        Config::set('mail.driver', 'disabled');
        LibraryRepositoryFactory::set(new MySqlLibraryRepository(static fn () => LibraryFixtures::NOW));
        Kernel::resetRouter();
        Setting::flush();
        $_SESSION = [];
        Auth::reset();
        $this->sent = [];
    }

    public function tearDown(): void
    {
        Mailer::useTransport(null);
        Config::set('ui.demo', $this->demo);
        if ($this->backups !== null) {
            foreach (glob($this->backups . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->backups);
            Config::set('storage.backups', BASE_PATH . '/storage/backups');
        }
        LibraryRepositoryFactory::set(null);
        Kernel::resetRouter();
        $_SESSION = [];
        Auth::reset();
        TestDatabase::release();
    }

    private function send(string $method, string $uri, array $post = [], ?int $userId = null, string $ip = '127.0.0.1'): Response
    {
        unset($_SESSION['user_id']);
        if ($userId !== null) {
            $_SESSION['user_id'] = $userId;
        }
        if ($method !== 'GET') {
            $post['_token'] = Csrf::token();
        }
        Session::resetForNextRequest();
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => $ip, 'HTTP_HOST' => 'localhost'];
        return (new Kernel())->handle(new Request($query, $post, $server, ['dl_locale' => 'en']));
    }

    private function valid(array $over = []): array
    {
        return $over + ['name' => 'Omar Test', 'email' => 'omar@example.invalid.test', 'subject' => 'A question about a book', 'message' => 'Is the second volume coming to the library soon?'];
    }

    public function testContactIsStoredAndHonestWhenEmailIsDisabled(): void
    {
        $page = $this->send('GET', '/contact');
        $this->assertSame(200, $page->status());
        $this->assertContains(__('contact.delivery_stored'), $page->body());
        $r = $this->send('POST', '/contact', $this->valid());
        $this->assertSame(303, $r->status());
        $after = $this->send('GET', '/contact')->body();
        $this->assertContains(__('contact.received_stored'), $after);
        $this->assertNotContains(__('contact.received_notified'), $after, 'never claims an e-mail was sent');
        $row = Database::first('SELECT name, email, status, notify_status FROM contact_messages');
        $this->assertSame(['name' => 'Omar Test', 'email' => 'omar@example.invalid.test', 'status' => 'new', 'notify_status' => 'not_configured'], $row);
    }

    public function testValidationHoneypotAndRateLimit(): void
    {
        $bad = $this->send('POST', '/contact', $this->valid(['email' => 'not-an-email', 'message' => 'short']));
        $this->assertSame(422, $bad->status());
        $this->assertContains(__('contact.invalid_email'), $bad->body());
        $this->assertContains(__('contact.invalid_message'), $bad->body());
        $this->assertContains('aria-invalid="true"', $bad->body());
        $this->assertSame(0, (int) Database::scalar('SELECT COUNT(*) FROM contact_messages'));

        $bot = $this->send('POST', '/contact', $this->valid(['website' => 'http://spam.example']));
        $this->assertSame(303, $bot->status(), 'bots get a neutral answer');
        $this->assertSame(0, (int) Database::scalar('SELECT COUNT(*) FROM contact_messages'), 'honeypot: nothing stored');

        $xss = $this->send('POST', '/contact', $this->valid(['name' => '<script>alert(1)</script>', 'email' => 'bad']));
        $this->assertNotContains('<script>alert(1)</script>', $xss->body());

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(303, $this->send('POST', '/contact', $this->valid(), null, '10.2.2.2')->status());
        }
        $this->assertSame(429, $this->send('POST', '/contact', $this->valid(), null, '10.2.2.2')->status());
        $this->assertSame(5, (int) Database::scalar('SELECT COUNT(*) FROM contact_messages'));
    }

    public function testNotificationStatusReflectsRealDelivery(): void
    {
        Database::query("UPDATE settings SET setting_value = 'team@example.invalid.test' WHERE setting_key = 'site.contact_email'");
        Setting::flush();
        Mailer::useTransport(function (string $to, string $subject, string $text): void {
            $this->sent[] = compact('to', 'subject', 'text');
        });
        $this->send('POST', '/contact', $this->valid());
        $this->assertSame('sent', (string) Database::scalar('SELECT notify_status FROM contact_messages ORDER BY id DESC'));
        $this->assertSame('team@example.invalid.test', $this->sent[0]['to']);
        $this->assertContains('Is the second volume', $this->sent[0]['text']);
        $this->assertContains(__('contact.received_notified'), $this->send('GET', '/contact')->body());

        Mailer::useTransport(static function (): void {
            throw new \RuntimeException('SMTP 550 mailbox unavailable');
        });
        $this->send('POST', '/contact', $this->valid(['subject' => 'Second message']));
        $this->assertSame('failed', (string) Database::scalar('SELECT notify_status FROM contact_messages ORDER BY id DESC'));
        $this->assertContains(__('contact.received_stored'), $this->send('GET', '/contact')->body(), 'failure is not reported as sent');
        $this->assertSame('failed', (string) Database::scalar('SELECT status FROM mail_log ORDER BY id DESC'));
        $this->assertSame(0, (int) Database::scalar("SELECT COUNT(*) FROM mail_log WHERE error LIKE '%team@%' OR error LIKE '%omar@%'"), 'no addresses in the mail log');
    }

    public function testAdminMessagesScreen(): void
    {
        $this->send('POST', '/contact', $this->valid());
        $id = (int) Database::scalar('SELECT id FROM contact_messages');
        $this->assertSame(302, $this->send('GET', '/admin/messages')->status(), 'guests are redirected');
        $this->assertSame(403, $this->send('GET', '/admin/messages', [], $this->ids['u_member'])->status(), 'members are refused');
        $list = $this->send('GET', '/admin/messages', [], $this->ids['u_admin']);
        $this->assertSame(200, $list->status());
        $this->assertContains('A question about a book', $list->body());
        $this->assertContains(__('admin.messages_help_stored'), $list->body());

        $r = $this->send('POST', '/admin/messages/' . $id . '/status', ['status' => 'archived'], $this->ids['u_admin']);
        $this->assertSame(303, $r->status());
        $this->assertSame('archived', (string) Database::scalar('SELECT status FROM contact_messages WHERE id = :id', ['id' => $id]));
        $this->assertSame(1, (int) Database::scalar("SELECT COUNT(*) FROM admin_activity_logs WHERE action = 'contact.archived'"));
        $this->assertSame(422, $this->send('POST', '/admin/messages/' . $id . '/status', ['status' => 'deleted'], $this->ids['u_admin'])->status());
    }

    public function testAboutPageIsNeutralAndBilingual(): void
    {
        $r = $this->send('GET', '/about');
        $this->assertSame(200, $r->status());
        $this->assertContains(__('about.features_title'), $r->body());
        $this->assertContains(brand_name(), $r->body());
    }

    public function testBackupScreenIsReadOnlyAndVerifies(): void
    {
        $this->backups = sys_get_temp_dir() . '/mauro-backups-' . bin2hex(random_bytes(4));
        mkdir($this->backups);
        Config::set('storage.backups', $this->backups);
        $good = $this->backups . '/db-20261119-010101.sql.gz';
        file_put_contents($good, gzencode("CREATE TABLE x (id int);\n-- Dump completed on 2026-11-19\n"));
        file_put_contents($good . '.sha256', hash_file('sha256', $good) . ' *db-20261119-010101.sql.gz');
        $broken = $this->backups . '/db-20261119-020202.sql.gz';
        file_put_contents($broken, gzencode("CREATE TABLE x (id int);\n"));
        $tampered = $this->backups . '/mysql-dev-manual.sql';
        file_put_contents($tampered, "-- Dump completed\n");
        file_put_contents($tampered . '.sha256', str_repeat('0', 64) . ' *mysql-dev-manual.sql');

        $this->assertSame(302, $this->send('GET', '/admin/backups')->status());
        $this->assertSame(403, $this->send('GET', '/admin/backups', [], $this->ids['u_member'])->status());
        $r = $this->send('GET', '/admin/backups', [], $this->ids['u_admin']);
        $this->assertSame(200, $r->status());
        $body = $r->body();
        $this->assertContains('db-20261119-010101.sql.gz', $body);
        $this->assertContains(__('admin.backup_integrity_invalid'), $body, 'incomplete dump flagged');
        $this->assertContains(__('admin.backup_checksum_mismatch'), $body, 'tampered file flagged');
        $this->assertNotContains('href="/storage', $body, 'no download links');
        $this->assertNotContains('<form', preg_replace('/<header.*?<\/header>|<aside.*?<\/aside>/s', '', $body) ?? $body, 'no restore/delete actions');
        $this->assertSame(BackupService::dir(), $this->backups);
        $this->assertSame(3, count(glob($this->backups . '/*.{gz,sql}', GLOB_BRACE) ?: []), 'nothing deleted or created');
    }
}
