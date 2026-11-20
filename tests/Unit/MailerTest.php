<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Services\Mail\Mailer;
use App\Services\Mail\SmtpTransport;
use Tests\TestCase;

/** E-mail service (B1): disabled by default, honest states, safe message building. */
final class MailerTest extends TestCase
{
    private array $saved = [];

    public function setUp(): void
    {
        $this->saved = (array) Config::get('mail', []);
        Mailer::useTransport(null);
    }

    public function tearDown(): void
    {
        Config::set('mail', $this->saved);
        Mailer::useTransport(null);
    }

    public function testDisabledUnlessFullyConfigured(): void
    {
        Config::set('mail', ['driver' => 'disabled']);
        $this->assertSame('disabled', Mailer::driver());
        $this->assertFalse(Mailer::canDeliver());
        Config::set('mail', ['driver' => 'smtp', 'host' => '', 'from_address' => 'a@b.test']);
        $this->assertSame('disabled', Mailer::driver(), 'smtp without a host is not delivery');
        Config::set('mail', ['driver' => 'smtp', 'host' => 'smtp.example.test', 'from_address' => 'not-an-address']);
        $this->assertSame('disabled', Mailer::driver(), 'smtp without a valid sender is not delivery');
        Config::set('mail', ['driver' => 'smtp', 'host' => 'smtp.example.test', 'from_address' => 'noreply@example.test']);
        $this->assertSame('smtp', Mailer::driver());
        $this->assertTrue(Mailer::canDeliver());
    }

    public function testLogDriverIsRefusedInProduction(): void
    {
        $env = Config::get('app.env');
        Config::set('mail', ['driver' => 'log']);
        Config::set('app.env', 'production');
        $this->assertSame('disabled', Mailer::driver());
        Config::set('app.env', $env);
    }

    public function testDisabledSendReportsDisabledNotSent(): void
    {
        Config::set('mail', ['driver' => 'disabled']);
        $this->assertSame('disabled', Mailer::send('test', 'x@example.test', 'S', 'T'));
    }

    public function testMessageIsUtf8DotStuffedAndHeaderSafe(): void
    {
        $t = new SmtpTransport(['host' => 'h', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'timeout' => 5]);
        $msg = $t->message('noreply@example.test', 'مكتبة', 'reader@example.test', 'اختر كلمة مرور', ".line starting with a dot\nnormal", null);
        $this->assertContains('Subject: =?UTF-8?B?', $msg);
        $this->assertContains('Content-Transfer-Encoding: base64', $msg);
        $this->assertFalse((bool) preg_match('/^\.[^.]/m', $msg), 'lines starting with a dot are escaped');
        $this->assertThrows(\RuntimeException::class, fn () => $t->send("a@b.test\r\nBcc: evil@x.test", 'x', 'to@example.test', 's', 't'));
    }
}
