<?php
declare(strict_types=1);

/**
 * Runs the front controllers the way php-fpm would, without a web server.
 * Both files are only a handful of lines, so including them is enough to
 * keep them at 100% coverage.
 *
 * headers are already sent in CLI, so header() warnings are expected noise.
 */

Harness::group('Front controllers');

function withoutHeaderWarnings(callable $fn): mixed
{
    $level = error_reporting(E_ALL & ~E_WARNING);

    try {
        return $fn();
    } finally {
        error_reporting($level);
    }
}

Harness::test('index.php renders the requested language', function (): void {
    foreach (['/' => 'lang="en"', '/ru.html' => 'lang="ru"'] as $uri => $needle) {
        $_SERVER['REQUEST_URI'] = $uri;
        unset($_SERVER['HTTP_IF_NONE_MATCH']);

        $html = withoutHeaderWarnings(function (): string {
            ob_start();
            require PUBLIC_DIR . '/index.php';

            return (string) ob_get_clean();
        });

        assertContains('<!DOCTYPE html>', $html, $uri);
        assertContains($needle, $html, $uri);
    }
});

Harness::test('index.php refuses anything but GET and HEAD', function (): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/';

    try {
        $body = withoutHeaderWarnings(function (): string {
            ob_start();
            require PUBLIC_DIR . '/index.php';

            return (string) ob_get_clean();
        });

        assertContains('Method Not Allowed', $body);
    } finally {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

Harness::test('index.php returns an empty body for HEAD', function (): void {
    $_SERVER['REQUEST_METHOD'] = 'HEAD';
    $_SERVER['REQUEST_URI'] = '/';

    try {
        $body = withoutHeaderWarnings(function (): string {
            ob_start();
            require PUBLIC_DIR . '/index.php';

            return (string) ob_get_clean();
        });

        assertSame('', $body);
    } finally {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

Harness::test('mcp.php refuses anything but POST', function (): void {
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $body = withoutHeaderWarnings(function (): string {
        ob_start();
        require PUBLIC_DIR . '/mcp.php';

        return (string) ob_get_clean();
    });

    assertContains('Method Not Allowed', $body);
});

Harness::test('mcp.php answers unauthenticated POSTs', function (): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_SERVER['HTTP_AUTHORIZATION']);

    $body = withoutHeaderWarnings(function (): string {
        ob_start();
        require PUBLIC_DIR . '/mcp.php';

        return (string) ob_get_clean();
    });

    assertContains('Unauthorized', $body);
});

Harness::test('mcp.php reads an authorized request body', function (): void {
    $token = str_repeat('a', 64);
    $previous = getenv('MCP_TOKEN');
    putenv('MCP_TOKEN=' . $token);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    unset($_SERVER['CONTENT_LENGTH']);

    try {
        $body = withoutHeaderWarnings(function (): string {
            ob_start();
            require PUBLIC_DIR . '/mcp.php';

            return (string) ob_get_clean();
        });

        assertContains('Parse error', $body);
    } finally {
        if ($previous === false) {
            putenv('MCP_TOKEN');
        } else {
            putenv('MCP_TOKEN=' . $previous);
        }

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
});

Harness::test('mcp.php rejects oversized bodies', function (): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_LENGTH'] = '1048577';
    unset($_SERVER['HTTP_AUTHORIZATION']);

    try {
        $body = withoutHeaderWarnings(function (): string {
            ob_start();
            require PUBLIC_DIR . '/mcp.php';

            return (string) ob_get_clean();
        });

        assertContains('Payload Too Large', $body);
    } finally {
        unset($_SERVER['CONTENT_LENGTH']);
    }
});
