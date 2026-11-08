<?php
declare(strict_types=1);

use Bio\App;

function mcpApp(string $root, string $token = 'secret'): App
{
    return new App($root, ['mcpToken' => $token]);
}

/** @return array{status: int, json: mixed, body: string, headers: array<string, string>} */
function sendMcp(App $app, array $payload, ?string $auth = 'Bearer secret'): array
{
    $response = $app->mcp((string) json_encode($payload), $auth);

    return [
        'status' => $response->status,
        'json' => json_decode($response->body, true),
        'body' => $response->body,
        'headers' => $response->headers,
    ];
}

function rpc(App $app, string $method, mixed $params = null, int|string|null $id = 1, ?string $auth = 'Bearer secret'): array
{
    $payload = ['jsonrpc' => '2.0', 'method' => $method];

    if ($id !== null) {
        $payload['id'] = $id;
    }

    if ($params !== null) {
        $payload['params'] = $params;
    }

    return sendMcp($app, $payload, $auth);
}

/** @return array{isError: bool, text: string, data: mixed} */
function callTool(App $app, string $name, array $args = []): array
{
    $response = rpc($app, 'tools/call', ['name' => $name, 'arguments' => $args]);
    $result = $response['json']['result'];

    return [
        'isError' => $result['isError'],
        'text' => $result['content'][0]['text'],
        'data' => json_decode($result['content'][0]['text'], true),
    ];
}

Harness::group('MCP: transport and auth');

Harness::test('rejects requests without a bearer token', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);

        foreach ([null, '', 'Bearer', 'Bearer wrong', 'Basic secret'] as $auth) {
            $response = $app->mcp('{}', $auth);
            assertSame(401, $response->status, var_export($auth, true));
            assertSame('Bearer', $response->headers['WWW-Authenticate']);
        }
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('accepts the configured token', function (): void {
    $root = makeWorkspace();

    try {
        assertSame(200, rpc(mcpApp($root), 'ping')['status']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('answers parse errors', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);

        $cases = [
            'not json' => 'nope',
            'wrong jsonrpc' => '{"jsonrpc":"1.0","id":1,"method":"ping"}',
            'missing method' => '{"jsonrpc":"2.0","id":1}',
        ];

        foreach ($cases as $label => $body) {
            $response = $app->mcp($body, 'Bearer secret');
            $json = json_decode($response->body, true);
            assertSame(-32700, $json['error']['code'], $label);
        }
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('acknowledges notifications with 202', function (): void {
    $root = makeWorkspace();

    try {
        $response = rpc(mcpApp($root), 'notifications/initialized', null, null);
        assertSame(202, $response['status']);
        assertSame('', $response['body']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::group('MCP: protocol');

Harness::test('initializes with a supported protocol version', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);
        $supported = rpc($app, 'initialize', ['protocolVersion' => '2025-03-26']);
        assertSame('2025-03-26', $supported['json']['result']['protocolVersion']);

        $fallback = rpc($app, 'initialize', ['protocolVersion' => '1999-01-01']);
        assertSame('2025-06-18', $fallback['json']['result']['protocolVersion']);

        $noParams = rpc($app, 'initialize');
        assertSame('2025-06-18', $noParams['json']['result']['protocolVersion']);
        assertSame('bio-mcp', $noParams['json']['result']['serverInfo']['name']);
        assertFalse($noParams['json']['result']['capabilities']['tools']['listChanged']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('answers ping with an empty object', function (): void {
    $root = makeWorkspace();

    try {
        $response = rpc(mcpApp($root), 'ping');
        assertContains('"result":{}', $response['body']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('rejects unknown methods', function (): void {
    $root = makeWorkspace();

    try {
        $response = rpc(mcpApp($root), 'resources/list');
        assertSame(-32601, $response['json']['error']['code']);
        assertContains('Method not found', $response['json']['error']['message']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('lists every tool with a schema', function (): void {
    $root = makeWorkspace();

    try {
        $tools = rpc(mcpApp($root), 'tools/list')['json']['result']['tools'];
        assertCount(12, $tools);

        foreach ($tools as $tool) {
            assertTrue(isset($tool['name'], $tool['description'], $tool['inputSchema']));
            assertMatches('~^[a-z_]+$~', $tool['name']);
        }
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('rejects tools/call without a name', function (): void {
    $root = makeWorkspace();

    try {
        $response = rpc(mcpApp($root), 'tools/call', ['arguments' => []]);
        assertSame(-32602, $response['json']['error']['code']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('reports unexpected failures as -32603', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);
        file_put_contents($root . '/content/site.json', '{broken');

        $response = rpc($app, 'tools/call', ['name' => 'get_content', 'arguments' => []]);
        assertSame(-32603, $response['json']['error']['code']);
        assertNotContains('broken', $response['body']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::group('MCP: reading');

Harness::test('returns the whole content tree', function (): void {
    $root = makeWorkspace();

    try {
        $result = callTool(mcpApp($root), 'get_content');
        assertFalse($result['isError']);
        assertSame(1, $result['data']['rev']);
        assertCount(5, $result['data']['content']['sections']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('returns the content schema', function (): void {
    $root = makeWorkspace();

    try {
        $result = callTool(mcpApp($root), 'get_schema');
        assertSame('object', $result['data']['type']);
        assertTrue(in_array('sections', $result['data']['required'], true));
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('lists sections', function (): void {
    $root = makeWorkspace();

    try {
        $result = callTool(mcpApp($root), 'list_sections');
        assertCount(5, $result['data']['sections']);
        assertSame('primary', $result['data']['sections'][0]['id']);
        assertSame(8, $result['data']['sections'][3]['items']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('filters links by section, visibility and language', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);

        assertCount(14, callTool($app, 'list_links')['data']['items']);
        assertCount(8, callTool($app, 'list_links', ['section' => 'social'])['data']['items']);
        assertCount(0, callTool($app, 'list_links', ['visible' => false])['data']['items']);

        $ru = callTool($app, 'list_links', ['section' => 'primary', 'lang' => 'ru']);
        assertSame('Телеграм-канал', $ru['data']['items'][0]['title']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::group('MCP: writing');

Harness::test('creates a link and derives its id', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);
        $result = callTool($app, 'create_link', [
            'section' => 'primary',
            'href' => 'https://example.com/new',
            'icon' => 'globe',
            'title' => ['en' => 'New Link', 'ru' => 'Новая ссылка'],
            'subtitle' => ['en' => 'example.com', 'ru' => 'example.com'],
        ]);

        assertFalse($result['isError'], $result['text']);
        assertSame('new-link', $result['data']['id']);
        assertSame(2, $result['data']['rev']);

        $links = callTool($app, 'list_links', ['section' => 'primary'])['data']['items'];
        assertCount(3, $links);
        assertSame('new-link', $links[2]['id']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('deduplicates generated ids and honours position', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);
        $args = [
            'section' => 'content',
            'href' => 'https://example.com/a',
            'icon' => 'globe',
            'title' => ['en' => 'Same', 'ru' => 'Same'],
        ];

        $first = callTool($app, 'create_link', $args);
        $second = callTool($app, 'create_link', ['position' => 1] + $args);
        $third = callTool($app, 'create_link', ['id' => 'custom-id'] + $args);

        assertSame('same', $first['data']['id']);
        assertSame('same-2', $second['data']['id']);
        assertSame('custom-id', $third['data']['id']);

        $links = callTool($app, 'list_links', ['section' => 'content'])['data']['items'];
        assertSame('same-2', $links[0]['id']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('falls back to a generic id', function (): void {
    $root = makeWorkspace();

    try {
        $result = callTool(mcpApp($root), 'create_link', [
            'section' => 'content',
            'id' => '',
            'href' => 'https://example.com/b',
            'icon' => 'globe',
            'title' => ['en' => '!!!', 'ru' => 'Без латиницы'],
        ]);

        assertSame('link', $result['data']['id']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('rejects a link for an unknown section', function (): void {
    $root = makeWorkspace();

    try {
        $result = callTool(mcpApp($root), 'create_link', [
            'section' => 'nope',
            'href' => 'https://example.com',
            'icon' => 'globe',
            'title' => ['en' => 'X', 'ru' => 'X'],
        ]);

        assertTrue($result['isError']);
        assertContains('Unknown section', $result['text']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('rejects invalid content with a tool error', function (): void {
    $root = makeWorkspace();

    try {
        $result = callTool(mcpApp($root), 'create_link', [
            'section' => 'content',
            'href' => 'ftp://example.com',
            'icon' => 'globe',
            'title' => ['en' => 'Bad', 'ru' => 'Bad'],
        ]);

        assertTrue($result['isError']);
        assertContains('href', $result['text']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('updates a link', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);
        $result = callTool($app, 'update_link', [
            'id' => 'telegram',
            'subtitle' => ['en' => 'Updated', 'ru' => 'Обновлено'],
        ]);

        assertFalse($result['isError'], $result['text']);
        assertSame(2, $result['data']['rev']);

        $links = callTool($app, 'list_links', ['section' => 'primary', 'lang' => 'en'])['data']['items'];
        assertSame('Telegram Channel', $links[0]['title']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('refuses icon and logo at the same time', function (): void {
    $root = makeWorkspace();

    try {
        $result = callTool(mcpApp($root), 'update_link', [
            'id' => 'telegram',
            'logo' => 'assets/x.png',
        ]);

        assertTrue($result['isError']);
        assertContains('both icon and logo', $result['text']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('rejects unknown ids', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);

        foreach (['update_link', 'move_link', 'set_visibility', 'delete_link'] as $tool) {
            $result = callTool($app, $tool, ['id' => 'ghost', 'section' => 'content', 'visible' => false]);
            assertTrue($result['isError'], $tool);
        }

        $missing = callTool($app, 'delete_link', []);
        assertTrue($missing['isError']);
        assertContains('id is required', $missing['text']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('moves a link between sections', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);
        $result = callTool($app, 'move_link', ['id' => 'telegram', 'section' => 'content', 'position' => 1]);

        assertFalse($result['isError'], $result['text']);
        assertSame('content', $result['data']['section']);

        $content = callTool($app, 'list_links', ['section' => 'content'])['data']['items'];
        assertSame('telegram', $content[0]['id']);
        assertCount(1, callTool($app, 'list_links', ['section' => 'primary'])['data']['items']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('rejects moving into an unknown section', function (): void {
    $root = makeWorkspace();

    try {
        $result = callTool(mcpApp($root), 'move_link', ['id' => 'telegram', 'section' => 'nope']);
        assertTrue($result['isError']);
        assertContains('Unknown section', $result['text']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('hides and restores a link', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);

        $hidden = callTool($app, 'set_visibility', ['id' => 'facebook', 'visible' => false]);
        assertFalse($hidden['isError'], $hidden['text']);
        assertCount(1, callTool($app, 'list_links', ['visible' => false])['data']['items']);

        $invalid = callTool($app, 'set_visibility', ['id' => 'facebook']);
        assertTrue($invalid['isError']);
        assertContains('visible must be a boolean', $invalid['text']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('soft deletes by default and hard deletes on request', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);

        $soft = callTool($app, 'delete_link', ['id' => 'medium']);
        assertFalse($soft['data']['hard']);
        assertSame(1, count(callTool($app, 'list_links', ['visible' => false])['data']['items']));

        $hard = callTool($app, 'delete_link', ['id' => 'medium', 'hard' => true]);
        assertTrue($hard['data']['hard']);
        assertCount(1, callTool($app, 'list_links', ['section' => 'content'])['data']['items']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('updates settings from flat and nested payloads', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);

        $flat = callTool($app, 'update_settings', ['themeDefault' => 'dark', 'ignored' => true]);
        assertSame(['themeDefault' => 'dark'], $flat['data']['applied']);

        $nested = callTool($app, 'update_settings', ['settings' => ['footerStart' => 2020]]);
        assertSame(['footerStart' => 2020], $nested['data']['applied']);

        $html = callTool($app, 'preview', ['lang' => 'en'])['data']['html'];
        assertContains('data-theme-default="dark"', $html);
        assertContains('&copy; 2020 - ', $html);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('rejects invalid settings', function (): void {
    $root = makeWorkspace();

    try {
        $result = callTool(mcpApp($root), 'update_settings', ['email' => 'nope']);
        assertTrue($result['isError']);
        assertContains('settings.email', $result['text']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('toggles GA4 through settings', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);

        $result = callTool($app, 'update_settings', ['ga4' => 'G-ABCD123456']);
        assertFalse($result['isError'], $result['text']);
        assertSame(['ga4' => 'G-ABCD123456'], $result['data']['applied']);

        $html = callTool($app, 'preview')['data']['html'];
        assertContains('G-ABCD123456', $html);

        $bad = callTool($app, 'update_settings', ['ga4' => 'UA-1234']);
        assertTrue($bad['isError']);
        assertContains('settings.ga4', $bad['text']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('surfaces revision conflicts', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);
        callTool($app, 'update_settings', ['footerStart' => 2020]);

        $stale = callTool($app, 'update_settings', ['footerStart' => 2021, 'rev' => 1]);
        assertTrue($stale['isError']);
        assertContains('Revision conflict', $stale['text']);

        $fresh = callTool($app, 'update_settings', ['footerStart' => 2021, 'rev' => 2]);
        assertFalse($fresh['isError'], $fresh['text']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::group('MCP: preview and publish');

Harness::test('previews either language', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);

        $en = callTool($app, 'preview');
        assertSame('en', $en['data']['lang']);
        assertContains('<!DOCTYPE html>', $en['data']['html']);

        $ru = callTool($app, 'preview', ['lang' => 'ru']);
        assertContains('lang="ru"', $ru['data']['html']);

        $bad = callTool($app, 'preview', ['lang' => 'de']);
        assertTrue($bad['isError']);
        assertContains('lang must be', $bad['text']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('publish drops the html cache', function (): void {
    $root = makeWorkspace();

    try {
        $app = mcpApp($root);
        $app->page('/');
        assertTrue(is_file($root . '/cache/en.html'));

        $result = callTool($app, 'publish');
        assertSame(1, $result['data']['rev']);
        assertCount(2, $result['data']['urls']);
        assertFalse(is_file($root . '/cache/en.html'));
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('unknown tools are reported as tool errors', function (): void {
    $root = makeWorkspace();

    try {
        $result = callTool(mcpApp($root), 'drop_database');
        assertTrue($result['isError']);
        assertContains('Unknown tool', $result['text']);
    } finally {
        removeWorkspace($root);
    }
});
