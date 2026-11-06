<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Translator;
use Tests\TestCase;

/** Every translation key used in code/views exists in BOTH Arabic and English. */
final class TranslationCoverageTest extends TestCase
{
    public function testAllLiteralKeysAreTranslatedInBothLanguages(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/resources/views', \FilesystemIterator::SKIP_DOTS));
        $sources = [];
        foreach ($files as $f) {
            $sources[] = (string) file_get_contents($f->getPathname());
        }
        foreach (glob(BASE_PATH . '/app/Controllers/{,*/}*.php', GLOB_BRACE) as $f) {
            $sources[] = (string) file_get_contents($f);
        }
        preg_match_all("/__\\(\\s*'([a-z0-9_.]+)'\\s*[,)]/", implode("\n", $sources), $m);
        $keys = array_unique($m[1]);
        $this->assertTrue(count($keys) > 200, 'expected many keys, got ' . count($keys));
        foreach (['ar', 'en'] as $loc) {
            $lines = Translator::lines($loc);
            foreach ($keys as $k) {
                $this->assertTrue(isset($lines[$k]), "Missing [$loc] translation: $k");
            }
        }
    }

    public function testDynamicKeyFamiliesAreComplete(): void
    {
        $expected = [];
        foreach (['published', 'draft', 'archived'] as $s) { $expected[] = "admin.status_$s"; }
        foreach (['completed', 'processing', 'failed', 'queued'] as $s) { $expected[] = "admin.job_$s"; }
        foreach (['privacy' => 4, 'terms' => 4, 'copyright' => 3] as $page => $n) {
            $expected[] = "pages.$page.title";
            $expected[] = "pages.$page.intro";
            for ($i = 1; $i <= $n; $i++) { $expected[] = "pages.$page.s{$i}_title"; $expected[] = "pages.$page.s{$i}_text"; }
        }
        foreach (['open', 'protected', 'bilingual'] as $p) { $expected[] = "home.principle_{$p}_title"; $expected[] = "home.principle_{$p}_text"; }
        foreach ([403, 404, 405, 419, 429, 500, 503] as $code) { $expected[] = "errors.$code.title"; $expected[] = "errors.$code.message"; }
        foreach (['ar', 'en'] as $loc) {
            foreach ($expected as $k) {
                $this->assertTrue(Translator::has($k, $loc), "Missing [$loc] translation: $k");
            }
        }
    }

    public function testPlaceholdersMatchAcrossLanguages(): void
    {
        $ar = Translator::lines('ar');
        $en = Translator::lines('en');
        foreach ($ar as $k => $v) {
            preg_match_all('/:([a-z]+)/', $v, $a);
            preg_match_all('/:([a-z]+)/', $en[$k], $b);
            $pa = $a[1]; $pb = $b[1]; sort($pa); sort($pb);
            $this->assertSame($pa, $pb, "Placeholder mismatch in $k");
        }
    }

    public function testLongestPlaceholderWins(): void
    {
        Translator::setLocale('en');
        $this->assertSame('Showing 1–14 of 14', __('catalog.showing', ['from' => 1, 'to' => 14, 'total' => 14]));
        Translator::setLocale('ar');
    }
}
