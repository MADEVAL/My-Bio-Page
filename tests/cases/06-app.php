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
        assertSame('DENY', $response->headers['X-Frame-Options']);
        assertContains("default-src 'self'", $response->headers['Content-Security-Policy']);
        assertContains('sha256-', $response->headers['Content-Security-Policy']);
        assertNotContains("script-src 'self' 'unsafe-inline'", $response->headers['Content-Security-Policy']);
        assertNotContains('includeSubDomains', $response->headers['Strict-Transport-Security']);
        assertNotContains("\r", \Bio\Theme::script());
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

Harness::test('changes the etag after an out-of-band edit', function (): void {
    $root = makeWorkspace();

    try {
        $first = (new App($root, ['mcpToken' => 'secret']))->page('/');
        $oldEtag = $first->headers['ETag'];

        $data = seedData();
        $data['meta']['en']['title'] = 'Changed out of band';
        writeSite($root, $data);

        $second = (new App($root, ['mcpToken' => 'secret']))->page('/');
        assertNotSame($oldEtag, $second->headers['ETag']);
        assertContains('Changed out of band', $second->body);

        $conditional = (new App($root, ['mcpToken' => 'secret']))->page('/', $oldEtag);
        assertSame(200, $conditional->status);
        assertContains('Changed out of band', $conditional->body);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('returns an empty body for HEAD', function (): void {
    $root = makeWorkspace();

    try {
        $response = (new App($root, ['mcpToken' => 'secret']))->page('/', null, true);
        assertSame(200, $response->status);
        assertSame('', $response->body);
        assertMatches('~^W/"[0-9a-f]{40}"$~', $response->headers['ETag']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('serves the page when the cache cannot be written', function (): void {
    $root = makeWorkspace();

    try {
        mkdir($root . '/cache/en.html', 0775, true);
        $response = (new App($root, ['mcpToken' => 'secret']))->page('/');

        assertSame(200, $response->status);
        assertContains('Yevhen Leonidov', $response->body);
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

        assertNotSame($app->page('/')->headers['ETag'], $app->page('/ru.html')->headers['ETag']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('accepts the language as a query parameter', function (): void {
    $root = makeWorkspace();

    try {
        $app = new App($root, ['mcpToken' => 'secret']);

        assertSame('ru', $app->page('/?lang=ru')->headers['Content-Language']);
        assertSame('ru', $app->page('/index.php?lang=ru')->headers['Content-Language']);
        assertSame('en', $app->page('/?lang=en')->headers['Content-Language']);
        assertSame('en', $app->page('/?lang=de')->headers['Content-Language']);
        assertSame('en', $app->page('/?lang[]=ru')->headers['Content-Language']);
        assertSame('en', $app->page('/?foo=1')->headers['Content-Language']);
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

Harness::test('serves a generated robots.txt', function (): void {
    $root = makeWorkspace();

    try {
        $response = (new App($root, ['mcpToken' => 'secret']))->page('/robots.txt');

        assertSame(200, $response->status);
        assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']);
        assertContains('Disallow: /mcp', $response->body);
        assertContains('Sitemap: https://bio.leonidov.dev/sitemap.xml', $response->body);
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

Harness::test('cleans up partial output when the template fails', function (): void {
    $root = makeWorkspace();

    try {
        file_put_contents(
            $root . '/templates/page.php',
            '<?php echo "PARTIAL"; throw new RuntimeException("boom");'
        );

        $response = (new App($root, ['mcpToken' => 'secret']))->page('/');

        assertSame(500, $response->status);
        assertSame("Internal Server Error\n", $response->body);
        assertNotContains('PARTIAL', $response->body);
    } finally {
        removeWorkspace($root);
    }
});

Harness::group('App: configuration');

Harness::test('ignores a legacy .env and provisions the app token', function (): void {
    $root = makeWorkspace();
    $tokenFile = tokenPath($root);

    try {
        @unlink($tokenFile);
        putenv('MCP_TOKEN');
        putenv('BIO_TOKEN_FILE');
        file_put_contents($root . '/.env', "MCP_TOKEN=from-env-file\n");

        $app = new App($root);
        assertTrue(is_file($tokenFile));

        $token = trim((string) file_get_contents($tokenFile));
        assertSame(200, $app->mcp('', 'Bearer ' . $token)->status);
        assertSame(401, $app->mcp('', 'Bearer from-env-file')->status);
    } finally {
        @unlink($tokenFile);
        removeWorkspace($root);
    }
});

Harness::test('reads the token file inside the app directory', function (): void {
    $root = makeWorkspace();
    $tokenFile = tokenPath($root);

    try {
        putenv('MCP_TOKEN');
        putenv('BIO_TOKEN_FILE');
        $fileToken = str_repeat('f', 64);
        file_put_contents($tokenFile, $fileToken . "\n");

        $app = new App($root);

        assertSame(200, $app->mcp('', 'Bearer ' . $fileToken)->status);
        assertSame(401, $app->mcp('', 'Bearer wrong')->status);
    } finally {
        @unlink($tokenFile);
        removeWorkspace($root);
    }
});

Harness::test('the environment beats the token file', function (): void {
    $root = makeWorkspace();
    $tokenFile = tokenPath($root);

    try {
        putenv('BIO_TOKEN_FILE');
        $fileToken = str_repeat('f', 64);
        $envToken = str_repeat('e', 64);
        file_put_contents($tokenFile, $fileToken);
        putenv('MCP_TOKEN=' . $envToken);

        $app = new App($root);

        assertSame(200, $app->mcp('', 'Bearer ' . $envToken)->status);
        assertSame(401, $app->mcp('', 'Bearer ' . $fileToken)->status);
    } finally {
        putenv('MCP_TOKEN');
        @unlink($tokenFile);
        removeWorkspace($root);
    }
});

Harness::test('falls back to the environment variable', function (): void {
    $root = makeWorkspace();

    try {
        $envToken = str_repeat('a', 64);
        putenv('MCP_TOKEN=' . $envToken);
        $app = new App($root);
        assertSame(200, $app->mcp('', 'Bearer ' . $envToken)->status);
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
