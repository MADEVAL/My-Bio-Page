<?php
declare(strict_types=1);

/**
 * End-to-end HTTP test: boots `php -S` with the dev router, which mirrors the
 * nginx rules, and talks to it over real sockets.
 *
 *   php tests/http.php
 */

const HOST = '127.0.0.1';
const PORT = 8131;
const TOKEN = 'integration-token-0123456789abcdef';

require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/workspace.php';

function copyDir(string $source, string $dest, array $skip = []): void
{
    $real = realpath($source);

    if ($real === false) {
        throw new RuntimeException('Directory is missing: ' . $source);
    }

    if (!is_dir($dest)) {
        mkdir($dest, 0775, true);
    }

    $prefix = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $item) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($prefix)));

        foreach ($skip as $prefixToSkip) {
            if (str_starts_with($relative, $prefixToSkip)) {
                continue 2;
            }
        }

        $target = $dest . '/' . $relative;

        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0775, true);
            }
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

function copyApp(string $root): void
{
    copyDir(APP_DIR, $root . '/app', [
        'content/site.json',
        'content/backups/',
        'content/.audit.jsonl',
        'content/site.json.lock',
        'cache/',
        'bio.token',
    ]);
    copyDir(PUBLIC_DIR, $root . '/public');
}

/** @return array{status: int, headers: array<string, string>, body: string} */
function request(string $method, string $path, array $headers = [], ?string $body = null): array
{
    $ch = curl_init('http://' . HOST . ':' . PORT . $path);
    $lines = [];

    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $lines,
        CURLOPT_TIMEOUT => 10,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    $responseHeaders = [];

    foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
    }

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => substr($raw, $headerSize)];
}

function rpc(string $method, array $params, string $token = TOKEN): array
{
    $response = request('POST', '/mcp', [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
    ], (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ]));

    return ['status' => $response['status'], 'json' => json_decode($response['body'], true)];
}

// ---------------------------------------------------------------------------
// Boot the server
// ---------------------------------------------------------------------------
$root = sys_get_temp_dir() . '/bio-http-' . bin2hex(random_bytes(6));
mkdir($root, 0775, true);
copyApp($root);

$php = PHP_BINARY;
$command = [$php, '-d', 'display_errors=1', '-S', HOST . ':' . PORT, '-t', $root . '/public', __DIR__ . '/router.php'];

$descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
$environment = getenv();
$environment['BIO_ROOT'] = $root . '/public';
$environment['MCP_TOKEN'] = TOKEN;

$server = proc_open($command, $descriptors, $pipes, null, $environment);

if (!is_resource($server)) {
    fwrite(STDERR, "Cannot start the PHP server\n");
    exit(2);
}

$ready = false;

for ($attempt = 0; $attempt < 100; $attempt++) {
    $probe = @fsockopen(HOST, PORT, $errno, $errstr, 0.1);

    if (is_resource($probe)) {
        fclose($probe);
        $ready = true;
        break;
    }

    usleep(100_000);
}

if (!$ready) {
    proc_terminate($server);
    fwrite(STDERR, "Server did not start\n");
    exit(2);
}

try {
    Harness::group('HTTP: pages');

    Harness::test('GET / serves the english page', function (): void {
        $response = request('GET', '/');

        assertSame(200, $response['status']);
        assertContains('text/html', $response['headers']['content-type']);
        assertContains('Yevhen Leonidov', $response['body']);
        assertContains('public, max-age=60', $response['headers']['cache-control']);
        assertTrue(isset($response['headers']['etag']));
        assertContains('sha256-', $response['headers']['content-security-policy']);
        assertNotContains("'unsafe-inline'", $response['headers']['content-security-policy']);
    });

    Harness::test('HEAD / returns headers without a body', function (): void {
        $response = request('HEAD', '/');

        assertSame(200, $response['status']);
        assertSame('', $response['body']);
        assertTrue(isset($response['headers']['etag']));
    });

    Harness::test('ETag produces a 304', function (): void {
        $first = request('GET', '/');
        $etag = $first['headers']['etag'];
        $second = request('GET', '/', ['If-None-Match' => $etag]);

        assertSame(304, $second['status']);
        assertSame('', $second['body']);
    });

    Harness::test('GET /ru.html serves russian', function (): void {
        $response = request('GET', '/ru.html');

        assertSame(200, $response['status']);
        assertContains('lang="ru"', $response['body']);
    });

    Harness::test('GET /sitemap.xml serves xml', function (): void {
        $response = request('GET', '/sitemap.xml');

        assertSame(200, $response['status']);
        assertContains('application/xml', $response['headers']['content-type']);
        assertContains('<urlset', $response['body']);
    });

    Harness::test('static assets are served directly', function (): void {
        $css = request('GET', '/styles.css');
        assertSame(200, $css['status']);
        assertContains('text/css', $css['headers']['content-type']);

        $robots = request('GET', '/robots.txt');
        assertSame(200, $robots['status']);
        assertContains('Disallow: /mcp', $robots['body']);

        $icon = request('GET', '/assets/favicon.svg');
        assertSame(200, $icon['status']);
    });

    Harness::test('unknown routes are 404', function (): void {
        assertSame(404, request('GET', '/nope')['status']);
    });

    Harness::test('private directories are not served', function (): void {
        foreach (['/content/site.seed.json', '/src/Content.php', '/templates/page.php', '/cache/en.html', '/.env'] as $path) {
            assertSame(404, request('GET', $path)['status'], $path);
        }
    });

    Harness::test('language works without any rewrite', function (): void {
        $response = request('GET', '/index.php?lang=ru');

        assertSame(200, $response['status']);
        assertContains('lang="ru"', $response['body']);
    });

    Harness::test('MCP is reachable at mcp.php', function (): void {
        assertSame(405, request('GET', '/mcp.php')['status']);
    });

    Harness::group('HTTP: MCP');

    Harness::test('MCP requires POST', function (): void {
        assertSame(405, request('GET', '/mcp')['status']);
    });

    Harness::test('MCP requires a token', function (): void {
        $response = request('POST', '/mcp', ['Content-Type' => 'application/json'], '{}');
        assertSame(401, $response['status']);
        assertContains('Bearer', $response['headers']['www-authenticate']);
    });

    Harness::test('MCP initializes over HTTP', function (): void {
        $response = rpc('initialize', ['protocolVersion' => '2025-06-18']);

        assertSame(200, $response['status']);
        assertSame('bio-mcp', $response['json']['result']['serverInfo']['name']);
    });

    Harness::test('MCP edits are live and publish refreshes the cache', function (): void {
        $before = rpc('tools/call', ['name' => 'get_content', 'arguments' => []]);
        $beforeContent = json_decode($before['json']['result']['content'][0]['text'], true);
        $initialRev = $beforeContent['rev'];

        $created = rpc('tools/call', [
            'name' => 'create_link',
            'arguments' => [
                'section' => 'content',
                'href' => 'https://example.com/http-test',
                'icon' => 'globe',
                'title' => ['en' => 'HTTP Test', 'ru' => 'HTTP Тест'],
                'subtitle' => ['en' => 'example.com', 'ru' => 'example.com'],
            ],
        ]);

        assertSame(200, $created['status']);
        assertFalse($created['json']['result']['isError']);

        // The cache key contains the revision, so a write invalidates itself.
        assertContains('HTTP Test', request('GET', '/')['body']);

        $published = rpc('tools/call', ['name' => 'publish', 'arguments' => []]);
        $payload = json_decode($published['json']['result']['content'][0]['text'], true);

        assertSame(200, $published['status']);
        assertSame($initialRev + 1, $payload['rev']);
        assertCount(2, $payload['urls']);
        assertContains('HTTP Test', request('GET', '/')['body']);
    });

    Harness::test('MCP rejects a bad token over HTTP', function (): void {
        $response = request('POST', '/mcp', [
            'Authorization' => 'Bearer nope',
            'Content-Type' => 'application/json',
        ], '{}');

        assertSame(401, $response['status']);
    });

    Harness::test('MCP rejects oversized bodies over HTTP', function (): void {
        $response = request('POST', '/mcp', [
            'Authorization' => 'Bearer ' . TOKEN,
            'Content-Type' => 'application/json',
        ], str_repeat('a', 1048577));

        assertSame(413, $response['status']);
    });
} finally {
    proc_terminate($server);
    proc_close($server);
    removeWorkspace($root);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo 'HTTP tests: ' . Harness::$passed . ' passed, ' . Harness::$failed . " failed\n";

if (Harness::$failed > 0) {
    echo "\nFailures:\n";

    foreach (Harness::$failures as [$name, $error]) {
        echo " - {$name}: {$error->getMessage()}\n";
    }
}

exit(Harness::$failed === 0 ? 0 : 1);
