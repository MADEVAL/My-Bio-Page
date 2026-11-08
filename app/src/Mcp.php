<?php
declare(strict_types=1);

namespace Bio;

use InvalidArgumentException;

/**
 * MCP server over Streamable HTTP (POST only, JSON responses).
 *
 * Implements: initialize, notifications/initialized, ping, tools/list, tools/call.
 * Every request must carry "Authorization: Bearer <token>".
 */
final class Mcp
{
    private const PROTOCOLS = ['2025-06-18', '2025-03-26'];

    public function __construct(
        private readonly Content $content,
        private readonly Renderer $renderer,
        private readonly string $cacheDir,
        private readonly string $token,
    ) {
    }

    public function handle(string $body, ?string $authorization, string $actor = 'mcp'): Response
    {
        if (!$this->authorized($authorization)) {
            return $this->json(
                401,
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Unauthorized']],
                ['WWW-Authenticate' => 'Bearer']
            );
        }

        $payload = json_decode($body, true);

        if (!is_array($payload) || ($payload['jsonrpc'] ?? null) !== '2.0' || !is_string($payload['method'] ?? null)) {
            return $this->error(null, -32700, 'Parse error');
        }

        // A request without an id is a notification: acknowledge and stop.
        if (!array_key_exists('id', $payload)) {
            return new Response(202, [], '');
        }

        $id = $payload['id'];

        try {
            $result = $this->dispatch($payload['method'], $payload['params'] ?? null);
        } catch (McpError $e) {
            return $this->error($id, $e->rpcCode, $e->getMessage());
        } catch (\Throwable $e) {
            // Unexpected failure (corrupt content, missing template, ...)
            return $this->error($id, -32603, 'Internal error');
        }

        return $this->json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function dispatch(string $method, mixed $params): mixed
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => (object) [],
            'tools/list' => ['tools' => $this->tools()],
            'tools/call' => $this->toolCall($params),
            default => throw new McpError("Method not found: {$method}", -32601),
        };
    }

    private function initialize(mixed $params): array
    {
        $requested = is_array($params) ? ($params['protocolVersion'] ?? null) : null;
        $version = is_string($requested) && in_array($requested, self::PROTOCOLS, true)
            ? $requested
            : self::PROTOCOLS[0];

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'bio-mcp', 'version' => '1.0.0'],
        ];
    }

    private function toolCall(mixed $params): array
    {
        if (!is_array($params) || !is_string($params['name'] ?? null)) {
            throw new McpError('Missing tool name', -32602);
        }

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $result = $this->execute($params['name'], $arguments);
        } catch (InvalidArgumentException | \DomainException $e) {
            // Expected user error: report it as a tool error, not a protocol error.
            return [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ];
        }

        return [
            'content' => [['type' => 'text', 'text' => $this->encode($result)]],
            'isError' => false,
        ];
    }

    private function execute(string $name, array $args): mixed
    {
        return match ($name) {
            'get_content' => ['rev' => $this->content->load()['rev'], 'content' => $this->content->load()],
            'get_schema' => self::schema(),
            'list_sections' => $this->listSections(),
            'list_links' => $this->listLinks($args),
            'create_link' => $this->createLink($args),
            'update_link' => $this->updateLink($args),
            'move_link' => $this->moveLink($args),
            'set_visibility' => $this->setVisibility($args),
            'delete_link' => $this->deleteLink($args),
            'update_settings' => $this->updateSettings($args),
            'preview' => $this->preview($args),
            'publish' => $this->publish(),
            default => throw new InvalidArgumentException("Unknown tool: {$name}"),
        };
    }

    private function listSections(): array
    {
        $data = $this->content->load();
        $out = [];

        foreach ($data['sections'] as $section) {
            $out[] = [
                'id' => $section['id'],
                'label' => $section['label'],
                'layout' => $section['layout'],
                'order' => $section['order'],
                'items' => count($section['items']),
            ];
        }

        return ['rev' => $data['rev'], 'sections' => $out];
    }

    private function listLinks(array $args): array
    {
        $data = $this->content->load();
        $sectionFilter = is_string($args['section'] ?? null) ? $args['section'] : null;
        $visibleFilter = isset($args['visible']) ? (bool) $args['visible'] : null;
        $lang = is_string($args['lang'] ?? null) ? $args['lang'] : null;
        $out = [];

        foreach ($data['sections'] as $section) {
            if ($sectionFilter !== null && $section['id'] !== $sectionFilter) {
                continue;
            }

            foreach ($section['items'] as $item) {
                if ($visibleFilter !== null && $item['visible'] !== $visibleFilter) {
                    continue;
                }

                $out[] = [
                    'id' => $item['id'],
                    'section' => $section['id'],
                    'type' => $item['type'],
                    'href' => $item['href'],
                    'order' => $item['order'],
                    'visible' => $item['visible'],
                    'title' => $lang === null ? $item['title'] : tr($item['title'], $lang),
                ];
            }
        }

        return ['rev' => $data['rev'], 'items' => $out];
    }

    private function createLink(array $args): array
    {
        $sectionId = (string) ($args['section'] ?? '');
        $created = null;

        $data = $this->content->mutate('mcp', $this->rev($args), function (array $d) use ($args, $sectionId, &$created): array {
            $index = null;

            foreach ($d['sections'] as $i => $section) {
                if ($section['id'] === $sectionId) {
                    $index = $i;
                }
            }

            if ($index === null) {
                throw new InvalidArgumentException("Unknown section: {$sectionId}");
            }

            $title = is_array($args['title'] ?? null) ? $args['title'] : ['en' => '', 'ru' => ''];
            $item = [
                'id' => $this->uniqueId($d, (string) ($args['id'] ?? ($title['en'] ?? 'link'))),
                'type' => $args['type'] ?? 'link',
                'href' => $args['href'] ?? '',
                'order' => 1,
                'visible' => $args['visible'] ?? true,
                'title' => $title,
                'subtitle' => is_array($args['subtitle'] ?? null) ? $args['subtitle'] : ['en' => '', 'ru' => ''],
            ];

            foreach (['icon', 'logo'] as $key) {
                if (isset($args[$key])) {
                    $item[$key] = $args[$key];
                }
            }

            $position = isset($args['position']) ? max(1, (int) $args['position']) : count($d['sections'][$index]['items']) + 1;
            array_splice($d['sections'][$index]['items'], $position - 1, 0, [$item]);
            $d['sections'][$index]['items'] = $this->reorder($d['sections'][$index]['items']);
            $created = $item['id'];

            return $d;
        });

        return ['rev' => $data['rev'], 'id' => $created];
    }

    private function updateLink(array $args): array
    {
        $id = $this->requireId($args);

        $data = $this->content->mutate('mcp', $this->rev($args), function (array $d) use ($args, $id): array {
            [$s, $i] = $this->locate($d, $id);
            $item = $d['sections'][$s]['items'][$i];

            foreach (['title', 'subtitle', 'href', 'type', 'icon', 'logo', 'visible'] as $key) {
                if (array_key_exists($key, $args)) {
                    $item[$key] = $args[$key];
                }
            }

            if (isset($item['icon'], $item['logo'])) {
                throw new InvalidArgumentException('An item cannot have both icon and logo');
            }

            $d['sections'][$s]['items'][$i] = $item;

            return $d;
        });

        return ['rev' => $data['rev'], 'id' => $id];
    }

    private function moveLink(array $args): array
    {
        $id = $this->requireId($args);
        $target = (string) ($args['section'] ?? '');

        $data = $this->content->mutate('mcp', $this->rev($args), function (array $d) use ($args, $id, $target): array {
            [$s, $i] = $this->locate($d, $id);
            $item = $d['sections'][$s]['items'][$i];
            array_splice($d['sections'][$s]['items'], $i, 1);
            $d['sections'][$s]['items'] = $this->reorder($d['sections'][$s]['items']);

            $targetIndex = null;

            foreach ($d['sections'] as $index => $section) {
                if ($section['id'] === $target) {
                    $targetIndex = $index;
                }
            }

            if ($targetIndex === null) {
                throw new InvalidArgumentException("Unknown section: {$target}");
            }

            $position = isset($args['position']) ? max(1, (int) $args['position']) : count($d['sections'][$targetIndex]['items']) + 1;
            array_splice($d['sections'][$targetIndex]['items'], $position - 1, 0, [$item]);
            $d['sections'][$targetIndex]['items'] = $this->reorder($d['sections'][$targetIndex]['items']);

            return $d;
        });

        return ['rev' => $data['rev'], 'id' => $id, 'section' => $target];
    }

    private function setVisibility(array $args): array
    {
        $id = $this->requireId($args);

        if (!is_bool($args['visible'] ?? null)) {
            throw new InvalidArgumentException('visible must be a boolean');
        }

        $data = $this->content->mutate('mcp', $this->rev($args), function (array $d) use ($args, $id): array {
            [$s, $i] = $this->locate($d, $id);
            $d['sections'][$s]['items'][$i]['visible'] = $args['visible'];

            return $d;
        });

        return ['rev' => $data['rev'], 'id' => $id, 'visible' => $args['visible']];
    }

    private function deleteLink(array $args): array
    {
        $id = $this->requireId($args);
        $hard = (bool) ($args['hard'] ?? false);

        $data = $this->content->mutate('mcp', $this->rev($args), function (array $d) use ($id, $hard): array {
            [$s, $i] = $this->locate($d, $id);

            if ($hard) {
                array_splice($d['sections'][$s]['items'], $i, 1);
                $d['sections'][$s]['items'] = $this->reorder($d['sections'][$s]['items']);
            } else {
                $d['sections'][$s]['items'][$i]['visible'] = false;
            }

            return $d;
        });

        return ['rev' => $data['rev'], 'id' => $id, 'hard' => $hard];
    }

    private function updateSettings(array $args): array
    {
        $patch = is_array($args['settings'] ?? null) ? $args['settings'] : $args;
        $allowed = ['email', 'themeDefault', 'footerStart', 'ga4'];
        $applied = [];

        $data = $this->content->mutate('mcp', $this->rev($args), function (array $d) use ($patch, $allowed, &$applied): array {
            foreach ($allowed as $key) {
                if (array_key_exists($key, $patch)) {
                    $d['settings'][$key] = $patch[$key];
                    $applied[$key] = $patch[$key];
                }
            }

            return $d;
        });

        return ['rev' => $data['rev'], 'applied' => $applied];
    }

    private function preview(array $args): array
    {
        $lang = is_string($args['lang'] ?? null) ? $args['lang'] : 'en';

        if (!in_array($lang, ['en', 'ru'], true)) {
            throw new InvalidArgumentException('lang must be en or ru');
        }

        return ['lang' => $lang, 'html' => $this->renderer->render($lang)];
    }

    private function publish(): array
    {
        Cache::flush($this->cacheDir);
        $data = $this->content->load();
        $base = rtrim($data['settings']['siteUrl'], '/');

        return ['rev' => $data['rev'], 'urls' => [$base . '/', $base . '/ru.html']];
    }

    private function locate(array $data, string $id): array
    {
        foreach ($data['sections'] as $s => $section) {
            foreach ($section['items'] as $i => $item) {
                if ($item['id'] === $id) {
                    return [$s, $i];
                }
            }
        }

        throw new InvalidArgumentException("Unknown item: {$id}");
    }

    private function reorder(array $items): array
    {
        foreach ($items as $index => $item) {
            $items[$index]['order'] = $index + 1;
        }

        return array_values($items);
    }

    private function uniqueId(array $data, string $base): string
    {
        $slug = strtolower((string) preg_replace('~[^a-zA-Z0-9]+~', '-', $base));
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'link';
        }

        $slug = substr($slug, 0, 40);
        $taken = [];

        foreach ($data['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $taken[$item['id']] = true;
            }
        }

        if (!isset($taken[$slug])) {
            return $slug;
        }

        $suffix = 2;

        while (isset($taken[$slug . '-' . $suffix])) {
            $suffix++;
        }

        return $slug . '-' . $suffix;
    }

    private function requireId(array $args): string
    {
        $id = $args['id'] ?? null;

        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('id is required');
        }

        return $id;
    }

    private function rev(array $args): ?int
    {
        return isset($args['rev']) ? (int) $args['rev'] : null;
    }

    private function authorized(?string $header): bool
    {
        if ($header === null || $this->token === '' || !str_starts_with($header, 'Bearer ')) {
            return false;
        }

        return hash_equals($this->token, substr($header, 7));
    }

    private function error(mixed $id, int $code, string $message): Response
    {
        return $this->json(200, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    /** @param array<string, string> $extra */
    private function json(int $status, array $payload, array $extra = []): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + $extra,
            $this->encode($payload)
        );
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<array<string, mixed>> */
    private function tools(): array
    {
        $rev = ['type' => 'integer', 'description' => 'Current revision for optimistic concurrency'];

        return [
            [
                'name' => 'get_content',
                'description' => 'Read the whole site content tree, including the current revision.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'get_schema',
                'description' => 'Read the JSON Schema of the content tree before editing it.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'list_sections',
                'description' => 'List sections with their ids, labels, layout and item counts.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'list_links',
                'description' => 'List links, optionally filtered by section, visibility or language.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'section' => ['type' => 'string'],
                        'visible' => ['type' => 'boolean'],
                        'lang' => ['type' => 'string', 'enum' => ['en', 'ru']],
                    ],
                ],
            ],
            [
                'name' => 'create_link',
                'description' => 'Create a link and insert it at an optional position.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['section', 'href', 'title'],
                    'properties' => [
                        'section' => ['type' => 'string'],
                        'id' => ['type' => 'string', 'description' => 'Optional slug; derived from the title when omitted'],
                        'type' => ['type' => 'string', 'enum' => Content::ITEM_TYPES],
                        'href' => ['type' => 'string'],
                        'icon' => ['type' => 'string', 'enum' => Icons::keys()],
                        'logo' => ['type' => 'string'],
                        'title' => ['type' => 'object', 'properties' => ['en' => ['type' => 'string'], 'ru' => ['type' => 'string']]],
                        'subtitle' => ['type' => 'object', 'properties' => ['en' => ['type' => 'string'], 'ru' => ['type' => 'string']]],
                        'position' => ['type' => 'integer', 'minimum' => 1],
                        'visible' => ['type' => 'boolean'],
                        'rev' => $rev,
                    ],
                ],
            ],
            [
                'name' => 'update_link',
                'description' => 'Patch a link by id. Localised fields are replaced as a whole.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'title' => ['type' => 'object'],
                        'subtitle' => ['type' => 'object'],
                        'href' => ['type' => 'string'],
                        'type' => ['type' => 'string', 'enum' => Content::ITEM_TYPES],
                        'icon' => ['type' => 'string', 'enum' => Icons::keys()],
                        'logo' => ['type' => 'string'],
                        'visible' => ['type' => 'boolean'],
                        'rev' => $rev,
                    ],
                ],
            ],
            [
                'name' => 'move_link',
                'description' => 'Move a link to another section or position.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['id', 'section'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'section' => ['type' => 'string'],
                        'position' => ['type' => 'integer', 'minimum' => 1],
                        'rev' => $rev,
                    ],
                ],
            ],
            [
                'name' => 'set_visibility',
                'description' => 'Show or hide a link without deleting it.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['id', 'visible'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'visible' => ['type' => 'boolean'],
                        'rev' => $rev,
                    ],
                ],
            ],
            [
                'name' => 'delete_link',
                'description' => 'Delete a link. Soft by default (hides it); hard=true removes it.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'hard' => ['type' => 'boolean'],
                        'rev' => $rev,
                    ],
                ],
            ],
            [
                'name' => 'update_settings',
                'description' => 'Update email, themeDefault, footerStart or the GA4 measurement ID.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => ['type' => 'string'],
                        'themeDefault' => ['type' => 'string', 'enum' => Content::THEMES],
                        'footerStart' => ['type' => 'integer'],
                        'ga4' => ['type' => 'string', 'description' => 'GA4 measurement ID like G-XXXXXXXXXX; empty string disables analytics'],
                        'rev' => $rev,
                    ],
                ],
            ],
            [
                'name' => 'preview',
                'description' => 'Render a language to HTML without touching the cache.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['lang' => ['type' => 'string', 'enum' => ['en', 'ru']]],
                ],
            ],
            [
                'name' => 'publish',
                'description' => 'Invalidate the HTML cache so the next request renders fresh content.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['rev', 'settings', 'meta', 'profile', 'ui', 'sections'],
            'properties' => [
                'rev' => ['type' => 'integer', 'minimum' => 1],
                'settings' => [
                    'type' => 'object',
                    'required' => ['siteUrl', 'email', 'themeDefault', 'footerStart', 'photo', 'ogImage', 'personUrl', 'worksFor', 'lastmod'],
                    'properties' => [
                        'siteUrl' => ['type' => 'string', 'description' => 'https URL, no trailing slash'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'themeDefault' => ['type' => 'string', 'enum' => Content::THEMES],
                        'footerStart' => ['type' => 'integer'],
                        'ga4' => ['type' => 'string', 'description' => 'GA4 measurement ID like G-XXXXXXXXXX, empty disables analytics'],
                        'photo' => ['type' => 'string'],
                        'ogImage' => ['type' => 'string'],
                        'personUrl' => ['type' => 'string'],
                        'worksFor' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'url' => ['type' => 'string']]],
                        'lastmod' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
                    ],
                ],
                'meta' => [
                    'type' => 'object',
                    'description' => 'Per language: title, description, ogAlt, siteName, jobTitle, personName, alternateName',
                ],
                'profile' => ['type' => 'object', 'description' => 'Per language: name, role, bio, alt'],
                'ui' => ['type' => 'object', 'description' => 'Per language: switchLang, copyEmail, themeToLight, themeToDark, copied, copyFailed, controls'],
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'label', 'srLabel', 'layout', 'order', 'items'],
                        'properties' => [
                            'id' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,40}$'],
                            'label' => ['type' => 'object', 'description' => 'en/ru strings or null for a visually hidden heading'],
                            'srLabel' => ['type' => 'object', 'description' => 'en/ru strings, always required'],
                            'layout' => ['type' => 'string', 'enum' => Content::LAYOUTS],
                            'order' => ['type' => 'integer', 'minimum' => 1],
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['id', 'type', 'href', 'order', 'visible', 'title'],
                                    'properties' => [
                                        'id' => ['type' => 'string'],
                                        'type' => ['type' => 'string', 'enum' => Content::ITEM_TYPES],
                                        'href' => ['type' => 'string'],
                                        'icon' => ['type' => 'string', 'enum' => Icons::keys()],
                                        'logo' => ['type' => 'string'],
                                        'order' => ['type' => 'integer', 'minimum' => 1],
                                        'visible' => ['type' => 'boolean'],
                                        'title' => ['type' => 'object'],
                                        'subtitle' => ['type' => 'object'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
