<?php
declare(strict_types=1);

namespace Bio;

/**
 * Everything search engines and social networks need, derived from content.
 * No metadata is written by hand anywhere else.
 */
final class Seo
{
    private const LOCALES = ['en' => 'en_US', 'ru' => 'ru_RU'];

    /** @return array<string, mixed> */
    public static function build(array $data, string $lang): array
    {
        $settings = $data['settings'];
        $meta = $data['meta'][$lang];
        $base = rtrim($settings['siteUrl'], '/');
        $canonical = $base . ($lang === 'en' ? '/' : '/ru.html');
        $image = $base . '/' . ltrim($settings['ogImage'], '/');

        return [
            'lang' => $lang,
            'title' => $meta['title'],
            'description' => $meta['description'],
            'canonical' => $canonical,
            'alternates' => [
                'en' => $base . '/',
                'ru' => $base . '/ru.html',
                'x-default' => $base . '/',
            ],
            'og' => [
                'title' => $meta['title'],
                'description' => $meta['description'],
                'image' => $image,
                'imageAlt' => $meta['ogAlt'],
                'url' => $canonical,
                'siteName' => $meta['siteName'],
                'locale' => self::LOCALES[$lang],
                'localeAlternate' => self::LOCALES[$lang === 'en' ? 'ru' : 'en'],
            ],
            'twitter' => [
                'title' => $meta['title'],
                'description' => $meta['description'],
                'image' => $image,
                'imageAlt' => $meta['ogAlt'],
            ],
            'jsonld' => self::jsonLd($data, $lang, $base, $image),
        ];
    }

    private static function jsonLd(array $data, string $lang, string $base, string $image): string
    {
        $settings = $data['settings'];
        $meta = $data['meta'][$lang];
        $sameAs = [];

        foreach ($data['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if ($item['visible'] && str_starts_with($item['href'], 'http')) {
                    $sameAs[] = $item['href'];
                }
            }
        }

        $graph = [
            '@context' => 'https://schema.org',
            '@type' => 'ProfilePage',
            'inLanguage' => $lang,
            'url' => $base . ($lang === 'en' ? '/' : '/ru.html'),
            'mainEntity' => [
                '@type' => 'Person',
                'name' => $meta['personName'],
                'alternateName' => $meta['alternateName'],
                'url' => $settings['personUrl'],
                'image' => $base . '/' . ltrim($settings['photo'], '/'),
                'jobTitle' => $meta['jobTitle'],
                'email' => 'mailto:' . $settings['email'],
                'worksFor' => [
                    '@type' => 'Organization',
                    'name' => $settings['worksFor']['name'],
                    'url' => $settings['worksFor']['url'],
                ],
                'sameAs' => array_values(array_unique($sameAs)),
            ],
        ];

        return (string) json_encode(
            $graph,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT
        );
    }

    public static function sitemap(array $data): string
    {
        $settings = $data['settings'];
        $base = rtrim($settings['siteUrl'], '/');
        $lastmod = $settings['lastmod'];
        $alternates = [
            ['en', $base . '/'],
            ['ru', $base . '/ru.html'],
            ['x-default', $base . '/'],
        ];

        $entries = '';

        foreach ([[$base . '/', '1.0'], [$base . '/ru.html', '0.9']] as [$loc, $priority]) {
            $entries .= "    <url>\n";
            $entries .= '        <loc>' . e($loc) . "</loc>\n";
            $entries .= '        <lastmod>' . e($lastmod) . "</lastmod>\n";
            $entries .= "        <changefreq>monthly</changefreq>\n";
            $entries .= '        <priority>' . $priority . "</priority>\n";

            foreach ($alternates as [$hreflang, $href]) {
                $entries .= '        <xhtml:link rel="alternate" hreflang="' . e($hreflang)
                    . '" href="' . e($href) . "\"/>\n";
            }

            $entries .= "    </url>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
            . '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n"
            . $entries
            . '</urlset>' . "\n";
    }
}
