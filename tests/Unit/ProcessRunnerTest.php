<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProcessRunner;
use Tests\TestCase;

/**
 * External processes (pdftoppm, Playwright scripts): output on both streams must
 * never deadlock the runner, and the timeout must always kill a runaway process.
 * Regression: on Windows, pipes are always blocking; a child writing a lot to one
 * stream while PHP waited on the other hung forever (ui-check.js, 11 KB of output).
 */
final class ProcessRunnerTest extends TestCase
{
    public function testLargeOutputOnBothStreamsDoesNotDeadlock(): void
    {
        $start = microtime(true);
        $r = ProcessRunner::run([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("e", 300000)); echo str_repeat("o", 300000); fwrite(STDERR, "END");'], 30);
        $this->assertSame(0, $r['exit']);
        $this->assertFalse($r['timed_out']);
        $this->assertSame(300000, strlen($r['stdout']));
        $this->assertSame(300003, strlen($r['stderr']));
        $this->assertTrue(str_ends_with($r['stderr'], 'END'), 'stderr captured completely');
        $this->assertTrue(microtime(true) - $start < 20, 'finished well before the timeout');
    }

    public function testTimeoutKillsARunawayProcess(): void
    {
        $start = microtime(true);
        $r = ProcessRunner::run([PHP_BINARY, '-r', 'echo "started"; sleep(30);'], 2);
        $this->assertTrue($r['timed_out']);
        $this->assertSame(-1, $r['exit']);
        $this->assertTrue(microtime(true) - $start < 10, 'killed at the deadline');
    }

    public function testRunawayOutputIsCappedAndTheProcessKilled(): void
    {
        $start = microtime(true);
        // Writes without end on stderr (cap 1 MB): must be stopped long before the timeout.
        $r = ProcessRunner::run([PHP_BINARY, '-r', 'while (true) { fwrite(STDERR, str_repeat("x", 65536)); }'], 60);
        $this->assertSame(-2, $r['exit'], 'killed by the output cap');
        $this->assertFalse($r['timed_out']);
        $this->assertTrue(strlen($r['stderr']) <= 1_000_000, 'captured stderr never exceeds the cap');
        $this->assertTrue(microtime(true) - $start < 30, 'stopped by the cap, not by the timeout');
    }

    public function testNoTemporaryFilesAreLeftBehind(): void
    {
        $pattern = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mp[oe]*';
        $before = glob($pattern) ?: [];
        ProcessRunner::run([PHP_BINARY, '-r', 'echo "ok";'], 30);
        ProcessRunner::run([PHP_BINARY, '-r', 'sleep(30);'], 1);
        $this->assertSame([], array_values(array_diff(glob($pattern) ?: [], $before)), 'temp output files removed, also after a timeout');
    }

    public function testExtraEnvironmentReachesTheChildOnly(): void
    {
        $r = ProcessRunner::run([PHP_BINARY, '-r', 'echo getenv("MAURO_PR_TEST"), "|", getenv("PATH") !== false ? "path" : "nopath";'], 30, null, ['MAURO_PR_TEST' => 'v1']);
        $this->assertSame('v1|path', $r['stdout'], 'extra variable set, inherited environment kept');
        $this->assertFalse(getenv('MAURO_PR_TEST'), 'parent environment untouched');
    }

    public function testExitCodeAndArgumentsWithoutAShell(): void
    {
        $r = ProcessRunner::run([PHP_BINARY, '-r', 'echo $argv[1]; exit(3);', 'a & b | c'], 30);
        $this->assertSame(3, $r['exit']);
        $this->assertSame('a & b | c', $r['stdout'], 'arguments are not interpreted by a shell');
    }
}
