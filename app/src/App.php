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
    private readonly string $publicRoot;

    /** @param array<string, string>|null $config */
    public function __construct(private readonly string $root, ?array $config = null)
    {
        $this->config = $config ?? self::readEnv($root);
        $configuredPublicRoot = $this->config['publicRoot'] ?? '';
        $this->publicRoot = $configuredPublicRoot !== ''
            ? $configuredPublicRoot
            : (is_dir($root . '/public') ? $root . '/public' : dirname($root) . '/public');
        $this->content = new Content(
            $root . '/content/site.json',
            $root . '/content/site.seed.json',
            $root . '/content/backups',
            $root . '/content/.audit.jsonl',
            $this->publicRoot,
        );
        $this->renderer = new Renderer($this->content, $root . '/templates/page.php');
    }

    public function page(string $uri, ?string $ifNoneMatch = null, bool $head = false): Response
    {
        try {
            return $this->buildPage($uri, $ifNoneMatch, $head);
        } catch (\Throwable $e) {
            return new Response(
                500,
                ['Content-Type' => 'text/plain; charset=utf-8'] + self::securityHeaders(),
                $head ? '' : "Internal Server Error\n"
            );
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

    private function buildPage(string $uri, ?string $ifNoneMatch, bool $head): Response
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        if ($path === '/sitemap.xml') {
            return new Response(
                200,
                [
                    'Content-Type' => 'application/xml; charset=utf-8',
                    'Cache-Control' => 'public, max-age=300',
                ] + self::securityHeaders(),
                $head ? '' : Seo::sitemap($this->content->load())
            );
        }

        if ($path === '/robots.txt') {
            $data = $this->content->load();
            $base = rtrim($data['settings']['siteUrl'], '/');
            $body = "User-agent: *\nAllow: /\nDisallow: /mcp\nDisallow: /mcp.php\n\nSitemap: {$base}/sitemap.xml\n";

            return new Response(
                200,
                [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'public, max-age=300',
                ] + self::securityHeaders(),
                $head ? '' : $body
            );
        }

        $lang = match ($path) {
            '/', '/index.html', '/index.php' => self::langFromQuery($uri) ?? 'en',
            '/ru.html', '/ru', '/ru/' => 'ru',
            default => null,
        };

        if ($lang === null) {
            return new Response(
                404,
                ['Content-Type' => 'text/plain; charset=utf-8'] + self::securityHeaders(),
                $head ? '' : "Not found\n"
            );
        }

        $data = $this->content->load();
        $cache = new Cache($this->root . '/cache', Cache::keyFor($data, $this->cacheInputs()));

        $html = $cache->get($lang);

        if ($html === null) {
            $html = $this->renderer->render($lang);

            try {
                $cache->put($lang, $html);
            } catch (\Throwable) {
                // The cache is an optimisation. A read-only or full cache
                // directory must never take the public page down.
            }
        }

        $etag = 'W/"' . sha1($html) . '"';
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Language' => $lang,
            'Cache-Control' => 'public, max-age=60, must-revalidate',
            'ETag' => $etag,
        ] + self::securityHeaders();

        if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
            return new Response(304, $headers, '');
        }

        return new Response(200, $headers, $head ? '' : $html);
    }

    /**
     * Everything that can change the rendered HTML or its metadata. Source
     * files are folded in so a deploy that touches only PHP logic does not
     * leave an old page in the cache.
     *
     * @return list<string>
     */
    private function cacheInputs(): array
    {
        $files = [
            $this->root . '/templates/page.php',
            $this->root . '/content/site.seed.json',
            $this->root . '/content/site.json',
            $this->publicRoot . '/styles.css',
            $this->publicRoot . '/script.js',
        ];

        foreach (glob($this->root . '/src/*.php') ?: [] as $source) {
            $files[] = $source;
        }

        return $files;
    }

    /** @return array<string, string> */
    public static function securityHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Strict-Transport-Security' => 'max-age=31536000',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' " . Theme::cspHash() . " https://www.googletagmanager.com https://www.google-analytics.com; style-src 'self'; font-src 'self'; img-src 'self' data: https:; connect-src 'self' https://www.google-analytics.com https://*.google-analytics.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
        ];
    }

    /**
     * ?lang=ru works without any rewrite rules, which matters on vhosts
     * that cannot be touched.
     */
    private static function langFromQuery(string $uri): ?string
    {
        $query = parse_url($uri, PHP_URL_QUERY);

        if (!is_string($query)) {
            return null;
        }

        parse_str($query, $params);
        $lang = $params['lang'] ?? null;

        return is_string($lang) && in_array($lang, ['en', 'ru'], true) ? $lang : null;
    }

    /** @return array<string, string> */
    private static function readEnv(string $root): array
    {
        return ['mcpToken' => self::readToken($root)];
    }

    /**
     * Token resolution, safest first:
     *   1. MCP_TOKEN from the process environment (php-fpm pool env[])
     *   2. BIO_TOKEN_FILE, an explicit path
     *   3. bio.token inside the private application directory
     */
    private static function readToken(string $root): string
    {
        $fromEnv = getenv('MCP_TOKEN');

        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            $token = trim($fromEnv);

            return Token::valid($token) ? $token : '';
        }

        $custom = getenv('BIO_TOKEN_FILE');

        if (is_string($custom) && trim($custom) !== '') {
            $token = (new Token(trim($custom)))->ensure();

            return Token::valid($token) ? $token : '';
        }

        $token = (new Token($root . '/bio.token'))->ensure();

        return Token::valid($token) ? $token : '';
    }
}
