<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;

/**
 * Minimal assertion base used by tests/run.php. Kept API-compatible in spirit
 * with PHPUnit so tests can be ported once Composer dev dependencies can be
 * installed.
 */
abstract class TestCase
{
    public int $assertions = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    /** Mark the test as skipped (reported separately, never counted as passed). */
    protected function markSkipped(string $reason): never
    {
        throw new SkippedTest($reason);
    }

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== true) {
            throw new AssertionFailed($message ?: 'Failed asserting that value is true. Got: ' . var_export($value, true));
        }
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== false) {
            throw new AssertionFailed($message ?: 'Failed asserting that value is false. Got: ' . var_export($value, true));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed(($message ? "$message\n" : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed($message ?: "Failed asserting that output contains [$needle].");
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed($message ?: "Failed asserting that output does NOT contain [$needle].");
        }
    }

    /** @param callable(): mixed $fn */
    protected function assertThrows(string $class, callable $fn, ?callable $inspect = null): void
    {
        $this->assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                throw new AssertionFailed("Expected $class, got " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($inspect) {
                $inspect($e);
            }
            return;
        }
        throw new AssertionFailed("Expected exception $class was not thrown.");
    }
}

final class AssertionFailed extends RuntimeException
{
}

final class SkippedTest extends RuntimeException
{
}
