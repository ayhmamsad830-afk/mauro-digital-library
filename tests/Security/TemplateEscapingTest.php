<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\TestCase;

/**
 * Static XSS guard for templates. Every `<?= … ?>` is parsed with the PHP
 * tokenizer: a ternary's condition is never printed, and every printed piece
 * (each operand of a top-level "." concatenation, in each branch) must be a
 * constant string, a number, e(...) or a helper that escapes internally.
 * A new raw echo fails this test until it is reviewed.
 */
final class TemplateEscapingTest extends TestCase
{
    /** Escaping helpers / trusted-HTML contracts (documented in the component). */
    private const SAFE_CALLS = ['e', 'icon', 'component', 'csrf_field', 'method_field', 'nl2br', '$field' /* admin/_field partial: escapes label/help; control built with e() */];
    /** Variables that hold HTML built with e() by contract. */
    private const TRUSTED_HTML_VARS = [
        '$content' => 'layout body rendered by View',
        '$control' => 'admin/_field: control markup built with e() by callers',
        '$actions' => 'book-row: action markup built with e() by callers',
    ];
    /** Numeric-only variables used in SVG geometry. */
    private const NUMERIC_VARS = ['$W', '$H', '$x', '$h', '$hm', '$bw', '$i', '$n'];

    public function testEveryEchoIsEscapedOrConstant(): void
    {
        $bad = [];
        $count = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/resources/views', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $f) {
            preg_match_all('/<\?=(.*?)\?>/s', (string) file_get_contents($f->getPathname()), $m);
            foreach ($m[1] as $expr) {
                $count++;
                $expr = rtrim(trim($expr), ';');
                if (!self::printedSafe(self::tokens($expr))) {
                    $bad[] = str_replace(BASE_PATH . '/', '', $f->getPathname()) . ': ' . $expr;
                }
            }
        }
        $this->assertTrue($count > 1000, "scanned $count echoes");
        $this->assertSame([], $bad, "Unescaped template output:\n" . implode("\n", $bad));
    }

    public function testTheCheckerRejectsRawOutput(): void
    {
        foreach (['$book[\'title\']', '$x . $title', '$ok ? $name : \'\'', '\'<b>\' . $html', 'strtoupper($t)', '"a $b"', 'e($a) . $b'] as $raw) {
            $this->assertFalse(self::printedSafe(self::tokens($raw)), "should reject: $raw");
        }
        foreach (['e($a)', '$c ? \' x="\' . e($v) . \'"\' : \'\'', '($s ?? \'\') === $k ? \' aria-current="page"\' : \'\'', '(int) $p[\'n\']', '$H + 1'] as $ok) {
            $this->assertTrue(self::printedSafe(self::tokens($ok)), "should accept: $ok");
        }
    }

    /** @return list<array{0:int|string,1:string}> significant tokens */
    private static function tokens(string $expr): array
    {
        $out = [];
        foreach (array_slice(token_get_all('<?php ' . $expr . ';'), 1) as $t) {
            $t = is_array($t) ? [$t[0], $t[1]] : [$t, $t];
            if ($t[0] !== T_WHITESPACE && $t[0] !== T_COMMENT) {
                $out[] = $t;
            }
        }
        array_pop($out); // ';'
        return $out;
    }

    /** Split at a top-level separator (depth 0). @return list<list<array>> */
    private static function split(array $toks, string $sep): array
    {
        $parts = [[]];
        $depth = 0;
        foreach ($toks as $t) {
            if (in_array($t[1], ['(', '[', '{'], true)) { $depth++; }
            if (in_array($t[1], [')', ']', '}'], true)) { $depth--; }
            if ($depth === 0 && $t[0] === $sep) {
                $parts[] = [];
                continue;
            }
            $parts[count($parts) - 1][] = $t;
        }
        return $parts;
    }

    private static function printedSafe(array $toks): bool
    {
        // Ternary: only the branches are printed.
        $q = self::split($toks, '?');
        if (count($q) >= 2) {
            $branches = self::split(array_merge(...array_slice($q, 1)), ':');
            return count($branches) === 2 && self::printedSafe($branches[0]) && self::printedSafe($branches[1]);
        }
        foreach (self::split($toks, '.') as $piece) {
            if (!self::pieceSafe($piece)) {
                return false;
            }
        }
        return true;
    }

    private static function pieceSafe(array $p): bool
    {
        if ($p === []) {
            return false;
        }
        $src = implode('', array_column($p, 1));
        if (count($p) === 1 && $p[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            return true; // no interpolation possible
        }
        if (count($p) === 1 && in_array($p[0][0], [T_LNUMBER, T_DNUMBER], true)) {
            return true;
        }
        if (isset(self::TRUSTED_HTML_VARS[$src])) {
            return true;
        }
        // Whole piece is one call to an escaping helper: name ( ... ) with matching parens.
        $name = ltrim(strtolower($p[0][1]), '\\');
        if ((in_array($name, self::SAFE_CALLS, true) || preg_match('/^(\\\\?app\\\\core\\\\)?view$/i', $name)) && self::wholeCall($p)) {
            return $name !== 'nl2br' || (($p[2][1] ?? '') === 'e');
        }
        // (int) cast of anything
        if ($p[0][0] === T_INT_CAST) {
            return true;
        }
        // round()/max() over numeric vars, numeric arithmetic, str_repeat of a constant by an int
        if (preg_match('/^(round|max)\(/', $src) || $p[0][0] === T_VARIABLE || $p[0][0] === '(') {
            foreach ($p as $t) {
                if ($t[0] === T_VARIABLE && !in_array($t[1], self::NUMERIC_VARS, true)) {
                    return false;
                }
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING || $t[0] === T_STRING && !in_array($t[1], ['round', 'max'], true)) {
                    return false;
                }
            }
            return true;
        }
        if (preg_match("/^str_repeat\('★',(5-)?\(int\)\\\$r\['rating'\]\)$/", $src)) {
            return true;
        }
        return false;
    }

    private static function wholeCall(array $p): bool
    {
        // name [:: name] ( ... ) — the first "(" must close at the last token.
        $i = 1;
        while (isset($p[$i]) && $p[$i][1] !== '(') {
            if (!in_array($p[$i][0], [T_DOUBLE_COLON, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                return false;
            }
            $i++;
        }
        $depth = 0;
        for ($j = $i; $j < count($p); $j++) {
            $depth += $p[$j][1] === '(' ? 1 : ($p[$j][1] === ')' ? -1 : 0);
            if ($depth === 0) {
                return $j === count($p) - 1;
            }
        }
        return false;
    }
}
