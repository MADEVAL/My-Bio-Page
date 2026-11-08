<?php
declare(strict_types=1);

/**
 * Tiny test harness. Deliberately dependency-free: the project ships no
 * composer.json and no vendor directory.
 */
final class Harness
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var list<array{0: string, 1: Throwable}> */
    public static array $failures = [];

    public static function test(string $name, callable $fn): void
    {
        try {
            $fn();
            self::$passed++;
            echo "  ok    {$name}\n";
        } catch (\Throwable $e) {
            self::$failed++;
            self::$failures[] = [$name, $e];
            $where = basename($e->getFile()) . ':' . $e->getLine();
            echo "  FAIL  {$name}\n        {$e->getMessage()} ({$where})\n";
        }
    }

    public static function group(string $title): void
    {
        echo "\n== {$title} ==\n";
    }
}

function fail(string $message): never
{
    throw new \AssertionError($message);
}

function assertTrue(mixed $value, string $message = ''): void
{
    if ($value !== true) {
        fail(($message !== '' ? $message . ': ' : '') . 'expected true, got ' . var_export($value, true));
    }
}

function assertFalse(mixed $value, string $message = ''): void
{
    if ($value !== false) {
        fail(($message !== '' ? $message . ': ' : '') . 'expected false, got ' . var_export($value, true));
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
{
    if ($unexpected === $actual) {
        fail(($message !== '' ? $message . ': ' : '') . 'value must differ from ' . var_export($unexpected, true));
    }
}

function assertCount(int $expected, array $actual, string $message = ''): void
{
    if (count($actual) !== $expected) {
        fail(($message !== '' ? $message . ': ' : '') . "expected {$expected} items, got " . count($actual));
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . ': ' : '') . "string does not contain '{$needle}'");
    }
}

function assertNotContains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . ': ' : '') . "string must not contain '{$needle}'");
    }
}

function assertMatches(string $pattern, string $subject, string $message = ''): void
{
    if (preg_match($pattern, $subject) !== 1) {
        fail(($message !== '' ? $message . ': ' : '') . "string does not match {$pattern}");
    }
}

function assertThrows(string $class, callable $fn, string $messageContains = ''): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!$e instanceof $class) {
            fail("expected {$class}, got " . $e::class . ': ' . $e->getMessage());
        }

        if ($messageContains !== '' && !str_contains($e->getMessage(), $messageContains)) {
            fail("message '{$e->getMessage()}' does not contain '{$messageContains}'");
        }

        return $e;
    }

    fail("expected {$class}, nothing was thrown");
}
