<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Env;
use Tests\TestCase;

final class EnvTest extends TestCase
{
    public function testParsesQuotedCommentsAndBooleans(): void
    {
        $file = BASE_PATH . '/storage/private/temp/env_test_' . bin2hex(random_bytes(4));
        file_put_contents($file, implode("\n", [
            '# comment',
            'TEST_ENV_A="Digital Library"',
            "TEST_ENV_B='single # not comment'",
            'TEST_ENV_C=plain # trailing comment',
            'TEST_ENV_D=true',
            'TEST_ENV_E=false',
            'export TEST_ENV_F=exported',
            'lowercase=ignored',
            'TEST_ENV_G=',
        ]));
        try {
            Env::load($file);
        } finally {
            unlink($file);
        }
        $this->assertSame('Digital Library', Env::get('TEST_ENV_A'));
        $this->assertSame('single # not comment', Env::get('TEST_ENV_B'));
        $this->assertSame('plain', Env::get('TEST_ENV_C'));
        $this->assertSame(true, Env::get('TEST_ENV_D'));
        $this->assertSame(false, Env::get('TEST_ENV_E'));
        $this->assertSame('exported', Env::get('TEST_ENV_F'));
        $this->assertSame(null, Env::get('lowercase'));
        $this->assertSame('', Env::get('TEST_ENV_G'));
        $this->assertSame('dflt', Env::get('TEST_ENV_MISSING', 'dflt'));
    }
}
