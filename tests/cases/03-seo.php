<?php
declare(strict_types=1);

use Bio\Seo;

Harness::group('Seo');

Harness::test('english metadata', function (): void {
    $data = seedData();
    $base = seedSiteUrl();
    $seo = Seo::build($data, 'en');

    assertSame('en', $seo['lang']);
    assertSame($data['meta']['en']['title'], $seo['title']);
    assertSame($base . '/', $seo['canonical']);
    assertSame($base . '/ru.html', $seo['alternates']['ru']);
    assertSame($base . '/', $seo['alternates']['x-default']);
    assertSame('en_US', $seo['og']['locale']);
    assertSame('ru_RU', $seo['og']['localeAlternate']);
    assertSame($base . '/' . $data['settings']['ogImage'], $seo['og']['image']);
    assertSame($data['meta']['en']['title'], $seo['twitter']['title']);
});

Harness::test('russian metadata', function (): void {
    $data = seedData();
    $seo = Seo::build($data, 'ru');

    assertSame('ru', $seo['lang']);
    assertSame(seedSiteUrl() . '/ru.html', $seo['canonical']);
    assertSame('ru_RU', $seo['og']['locale']);
    assertSame('en_US', $seo['og']['localeAlternate']);
    assertSame($data['meta']['ru']['title'], $seo['title']);
});

Harness::test('json-ld exposes every visible profile', function (): void {
    $data = seedData();
    $base = seedSiteUrl();
    $ld = json_decode(Seo::build($data, 'en')['jsonld'], true, 512, JSON_THROW_ON_ERROR);

    $visibleLinks = [];

    foreach ($data['sections'] as $section) {
        foreach ($section['items'] as $item) {
            if ($item['visible'] && str_starts_with($item['href'], 'http')) {
                $visibleLinks[] = $item['href'];
            }
        }
    }

    assertSame('ProfilePage', $ld['@type']);
    assertSame('en', $ld['inLanguage']);
    assertSame('Person', $ld['mainEntity']['@type']);
    assertSame(count($visibleLinks), count($ld['mainEntity']['sameAs']));
    assertSame($visibleLinks[0], $ld['mainEntity']['sameAs'][0]);
    assertSame('mailto:' . $data['settings']['email'], $ld['mainEntity']['email']);
    assertSame($data['settings']['worksFor']['name'], $ld['mainEntity']['worksFor']['name']);
    assertSame($base . '/' . $data['settings']['photo'], $ld['mainEntity']['image']);
    assertSame($data['settings']['personUrl'], $ld['mainEntity']['url']);
});

Harness::test('json-ld keeps order and skips hidden items', function (): void {
    $data = seedData();
    $data['sections'][0]['items'][0]['visible'] = false;
    $ld = json_decode(Seo::build($data, 'en')['jsonld'], true, 512, JSON_THROW_ON_ERROR);

    $visibleLinks = [];

    foreach ($data['sections'] as $section) {
        foreach ($section['items'] as $item) {
            if ($item['visible'] && str_starts_with($item['href'], 'http')) {
                $visibleLinks[] = $item['href'];
            }
        }
    }

    assertSame(count($visibleLinks), count($ld['mainEntity']['sameAs']));
    assertSame($visibleLinks[0], $ld['mainEntity']['sameAs'][0]);
});

Harness::test('json-ld is language specific', function (): void {
    $data = seedData();
    $ld = json_decode(Seo::build($data, 'ru')['jsonld'], true, 512, JSON_THROW_ON_ERROR);

    assertSame('ru', $ld['inLanguage']);
    assertSame($data['meta']['ru']['personName'], $ld['mainEntity']['name']);
    assertSame($data['meta']['ru']['alternateName'], $ld['mainEntity']['alternateName']);
});

Harness::test('sitemap lists both languages with alternates', function (): void {
    $data = seedData();
    $base = seedSiteUrl();
    $xml = Seo::sitemap($data);

    assertContains('<loc>' . $base . '/</loc>', $xml);
    assertContains('<loc>' . $base . '/ru.html</loc>', $xml);
    assertContains('hreflang="x-default"', $xml);
    assertContains('<lastmod>' . $data['settings']['lastmod'] . '</lastmod>', $xml);
    assertNotSame(false, simplexml_load_string($xml));
});
