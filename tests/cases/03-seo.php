<?php
declare(strict_types=1);

use Bio\Seo;

Harness::group('Seo');

Harness::test('english metadata', function (): void {
    $seo = Seo::build(seedData(), 'en');

    assertSame('en', $seo['lang']);
    assertSame('Yevhen Leonidov | Software Architect', $seo['title']);
    assertSame('https://bio.leonidov.dev/', $seo['canonical']);
    assertSame('https://bio.leonidov.dev/ru.html', $seo['alternates']['ru']);
    assertSame('https://bio.leonidov.dev/', $seo['alternates']['x-default']);
    assertSame('en_US', $seo['og']['locale']);
    assertSame('ru_RU', $seo['og']['localeAlternate']);
    assertSame('https://bio.leonidov.dev/assets/OG.jpg', $seo['og']['image']);
    assertContains('Yevhen Leonidov', $seo['twitter']['title']);
});

Harness::test('russian metadata', function (): void {
    $seo = Seo::build(seedData(), 'ru');

    assertSame('ru', $seo['lang']);
    assertSame('https://bio.leonidov.dev/ru.html', $seo['canonical']);
    assertSame('ru_RU', $seo['og']['locale']);
    assertSame('en_US', $seo['og']['localeAlternate']);
    assertContains('Архитектор ПО', $seo['title']);
});

Harness::test('json-ld exposes every visible profile', function (): void {
    $ld = json_decode(Seo::build(seedData(), 'en')['jsonld'], true, 512, JSON_THROW_ON_ERROR);

    assertSame('ProfilePage', $ld['@type']);
    assertSame('en', $ld['inLanguage']);
    assertSame('Person', $ld['mainEntity']['@type']);
    assertSame(13, count($ld['mainEntity']['sameAs']));
    assertSame('https://t.me/leonidov_dev', $ld['mainEntity']['sameAs'][0]);
    assertSame('mailto:leonidovev@gmail.com', $ld['mainEntity']['email']);
    assertSame('GLOBUS.studio', $ld['mainEntity']['worksFor']['name']);
});

Harness::test('json-ld keeps order and skips hidden items', function (): void {
    $data = seedData();
    $data['sections'][0]['items'][0]['visible'] = false;
    $ld = json_decode(Seo::build($data, 'en')['jsonld'], true, 512, JSON_THROW_ON_ERROR);

    assertSame(12, count($ld['mainEntity']['sameAs']));
    assertSame('https://globus.studio', $ld['mainEntity']['sameAs'][0]);
});

Harness::test('json-ld is language specific', function (): void {
    $ld = json_decode(Seo::build(seedData(), 'ru')['jsonld'], true, 512, JSON_THROW_ON_ERROR);

    assertSame('ru', $ld['inLanguage']);
    assertSame('Евгений Леонидов', $ld['mainEntity']['name']);
    assertSame('Yevhen Leonidov', $ld['mainEntity']['alternateName']);
});

Harness::test('sitemap lists both languages with alternates', function (): void {
    $xml = Seo::sitemap(seedData());

    assertContains('<loc>https://bio.leonidov.dev/</loc>', $xml);
    assertContains('<loc>https://bio.leonidov.dev/ru.html</loc>', $xml);
    assertContains('hreflang="x-default"', $xml);
    assertContains('<lastmod>2026-11-08</lastmod>', $xml);
    assertNotSame(false, simplexml_load_string($xml));
});
