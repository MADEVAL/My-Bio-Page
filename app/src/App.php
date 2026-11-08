<?php
declare(strict_types=1);

namespace Bio;

/**
 * Composition root. Turns HTTP-ish input into a Response object so the whole
 * request path stays testable without a web server.
 */
final class App
{
    /** @var array<string, string> */
    private readonly array $config;

    private readonly Content $content;
    private readonly Renderer $renderer;

    /** @param array<string, string>|null $config */
    public function __construct(private readonly string $root, ?array $config = null)
    {
        $this->config = $config ?? self::readEnv($root);
        $this->content = new Content(
            $root . '/content/site.json',
            $root . '/content/site.seed.json',
            $root . '/content/backups',
            $root . '/content/.audit.jsonl',
        );
        $this->renderer = new Renderer($this->content, $root . '/templates/page.php');
    }

    public function page(string $uri, ?string $ifNoneMatch = null): Response
    {
        try {
            return $this->buildPage($uri, $ifNoneMatch);
        } catch (\Throwable $e) {
            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], "Internal Server Error\n");
        }
    }

    public function mcp(string $body, ?string $authorization, string $actor = 'mcp'): Response
    {
        $server = new Mcp(
            $this->content,
            $this->renderer,
            $this->root . '/cache',
            (string) ($this->config['mcpToken'] ?? '')
        );

        return $server->handle($body, $authorization, $actor);
    }

    private function buildPage(string $uri, ?string $ifNoneMatch): Response
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        if ($path === '/sitemap.xml') {
            return new Response(
                200,
                [
                    'Content-Type' => 'application/xml; charset=utf-8',
                    'Cache-Control' => 'public, max-age=300',
                    'X-Content-Type-Options' => 'nosniff',
                ],
                Seo::sitemap($this->content->load())
            );
        }

        $lang = match ($path) {
            '/', '/index.html', '/index.php' => 'en',
            '/ru.html', '/ru', '/ru/' => 'ru',
            default => null,
        };

        if ($lang === null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], "Not found\n");
        }

        $data = $this->content->load();
        $cache = new Cache($this->root . '/cache', Cache::keyFor($data, [
            $this->root . '/templates/page.php',
            $this->root . '/styles.css',
            $this->root . '/script.js',
        ]));

        $html = $cache->get($lang);

        if ($html === null) {
            $html = $this->renderer->render($lang);
            $cache->put($lang, $html);
        }

        $etag = $cache->etag();
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Language' => $lang,
            'Cache-Control' => 'public, max-age=60, must-revalidate',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
            return new Response(304, $headers, '');
        }

        return new Response(200, $headers, $html);
    }

    /** @return array<string, string> */
    private static function readEnv(string $root): array
    {
        $config = ['mcpToken' => (string) (getenv('MCP_TOKEN') ?: '')];
        $file = $root . '/.env';

        if (!is_file($file)) {
            return $config;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            if (trim($key) === 'MCP_TOKEN') {
                $config['mcpToken'] = trim($value, " \t\"'");
            }
        }

        return $config;
    }
}
