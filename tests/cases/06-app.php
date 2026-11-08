<?php
declare(strict_types=1);

use Bio\App;

Harness::group('App: pages');

Harness::test('serves the english page with cache headers', function (): void {
    $root = makeWorkspace();

    try {
        $app = new App($root, ['mcpToken' => 'secret']);
        $response = $app->page('/');

        assertSame(200, $response->status);
        assertContains('Yevhen Leonidov', $response->body);
        assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
        assertSame('en', $response->headers['Content-Language']);
        assertContains('public, max-age=60', $response->headers['Cache-Control']);
        assertMatches('~^W/"[0-9a-f]{40}"$~', $response->headers['ETag']);
        assertTrue(is_file($root . '/cache/en.html'));
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('answers 304 for a matching etag', function (): void {
    $root = makeWorkspace();

    try {
        $app = new App($root, ['mcpToken' => 'secret']);
        $first = $app->page('/');
        $second = $app->page('/', $first->headers['ETag']);

        assertSame(304, $second->status);
        assertSame('', $second->body);
        assertSame($first->headers['ETag'], $second->headers['ETag']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('serves the russian page and its aliases', function (): void {
    $root = makeWorkspace();

    try {
        $app = new App($root, ['mcpToken' => 'secret']);

        foreach (['/ru.html', '/ru', '/ru/'] as $uri) {
            $response = $app->page($uri);
            assertSame(200, $response->status, $uri);
            assertContains('lang="ru"', $response->body, $uri);
        }

        foreach (['/', '/index.html', '/index.php'] as $uri) {
            assertSame(200, $app->page($uri)->status, $uri);
        }
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('serves a generated sitemap', function (): void {
    $root = makeWorkspace();

    try {
        $response = (new App($root, ['mcpToken' => 'secret']))->page('/sitemap.xml');

        assertSame(200, $response->status);
        assertSame('application/xml; charset=utf-8', $response->headers['Content-Type']);
        assertContains('<urlset', $response->body);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('returns 404 for unknown routes', function (): void {
    $root = makeWorkspace();

    try {
        $response = (new App($root, ['mcpToken' => 'secret']))->page('/nope');

        assertSame(404, $response->status);
        assertSame("Not found\n", $response->body);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('returns 500 instead of leaking a stack trace', function (): void {
    $root = makeWorkspace();

    try {
        file_put_contents($root . '/content/site.json', '{broken');
        $response = (new App($root, ['mcpToken' => 'secret']))->page('/');

        assertSame(500, $response->status);
        assertSame("Internal Server Error\n", $response->body);
    } finally {
        removeWorkspace($root);
    }
});

Harness::group('App: configuration');

Harness::test('reads the token from .env and ignores noise', function (): void {
    $root = makeWorkspace();

    try {
        file_put_contents($root . '/.env', implode("\n", [
            '# comment',
            '',
            'NOT_A_TOKEN=1',
            'MCP_TOKEN="from-env-file"',
        ]));

        $app = new App($root);

        assertSame(200, $app->mcp('', 'Bearer from-env-file')->status);
        assertSame(401, $app->mcp('', 'Bearer wrong')->status);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('falls back to the environment variable', function (): void {
    $root = makeWorkspace();

    try {
        putenv('MCP_TOKEN=from-process');
        $app = new App($root);
        assertSame(200, $app->mcp('', 'Bearer from-process')->status);
    } finally {
        putenv('MCP_TOKEN');
        removeWorkspace($root);
    }
});

Harness::test('is locked down when no token is configured', function (): void {
    $root = makeWorkspace();

    try {
        $app = new App($root, ['mcpToken' => '']);
        assertSame(401, $app->mcp('', 'Bearer anything')->status);
    } finally {
        removeWorkspace($root);
    }
});
