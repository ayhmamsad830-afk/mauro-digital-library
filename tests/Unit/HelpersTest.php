<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Csrf;
use App\Core\Translator;
use Tests\TestCase;

final class HelpersTest extends TestCase
{
    public function testEscapeNeutralisesHtml(): void
    {
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
        $this->assertSame('&#039; onmouseover=&#039;x', e("' onmouseover='x"));
        $this->assertSame('', e(null));
    }

    public function testSafeRedirectRejectsOffsiteTargets(): void
    {
        $this->assertSame('/books?page=2', safe_redirect_path('/books?page=2'));
        foreach (['//evil.example', 'https://evil.example', '/\\evil.example', 'javascript:alert(1)', "/x\r\nLocation: y", ''] as $bad) {
            $this->assertSame('/', safe_redirect_path($bad), 'Should reject: ' . $bad);
        }
    }

    public function testCsrfTokenValidation(): void
    {
        $_SESSION = [];
        $token = Csrf::token();
        $this->assertSame(64, strlen($token));
        $this->assertSame($token, Csrf::token(), 'Token is stable within a session');
        $this->assertTrue(Csrf::validate($token));
        $this->assertFalse(Csrf::validate('x' . substr($token, 1)));
        $this->assertFalse(Csrf::validate(null));
        $this->assertFalse(Csrf::validate(''));
    }

    public function testTranslatorDefaultsToArabicRtlAndFallsBack(): void
    {
        Translator::setLocale('fr'); // unsupported → default
        $this->assertSame('ar', Translator::locale());
        $this->assertSame('rtl', Translator::direction());
        $this->assertSame('الرئيسية', __('nav.home'));
        Translator::setLocale('en');
        $this->assertSame('ltr', Translator::direction());
        $this->assertSame('Home', __('nav.home'));
        $this->assertSame('missing.key', __('missing.key'));
        Translator::setLocale('ar');
    }

    public function testArabicAndEnglishHaveIdenticalKeys(): void
    {
        $ar = array_keys(Translator::lines('ar'));
        $en = array_keys(Translator::lines('en'));
        sort($ar);
        sort($en);
        $this->assertSame($ar, $en, 'Translation files must define the same keys');
    }

    public function testCoAuthorsAreJoinedInStoredOrderPerLocale(): void
    {
        $book = ['authors' => [['name' => 'مُعِين خيري أبوعائشة'], ['name' => 'محمد سليمان اليونسي']]];
        Translator::setLocale('ar');
        $this->assertSame('مُعِين خيري أبوعائشة، محمد سليمان اليونسي', book_authors($book));
        Translator::setLocale('en');
        $this->assertSame('مُعِين خيري أبوعائشة and محمد سليمان اليونسي', book_authors($book));
        $this->assertSame('A, B, and C', book_authors(['authors' => [['name' => 'A'], ['name' => 'B'], ['name' => 'C']]]));
        $this->assertSame('Solo', book_authors(['authors' => [['name' => 'Solo']]]));
        $this->assertSame('', book_authors([]));
        Translator::setLocale('ar');
    }
}
