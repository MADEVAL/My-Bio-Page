<?php
declare(strict_types=1);

use Bio\Renderer;

function rendererFor(string $root, ?array $data = null): Renderer
{
    if ($data !== null) {
        writeSite($root, $data);
    }

    return new Renderer(makeContent($root), $root . '/templates/page.php');
}

function anchors(string $html): int
{
    return preg_match_all('~<a\s~', $html);
}

Harness::group('Renderer');

Harness::test('renders the english page', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $html = rendererFor($root)->render('en');

        assertContains('<html lang="en"', $html);
        assertContains(e($data['profile']['en']['name']), $html);
        assertContains(e($data['profile']['en']['role']), $html);
        assertContains('<title>' . e($data['meta']['en']['title']) . '</title>', $html);
        assertContains('rel="canonical" href="' . e(seedSiteUrl() . '/') . '"', $html);
        assertContains('class="social-grid"', $html);
        assertContains('class="email-wrapper"', $html);
        assertContains('id="copyEmail"', $html);
        assertContains('id="i18n"', $html);
        assertContains('hreflang="ru"', $html);
        assertContains('/styles.css?v=', $html);
        assertContains('/script.js?v=', $html);
        assertSame(1 + visibleItemCount($data), anchors($html));
        assertSame(renderedCounts($data)['socials'], substr_count($html, 'class="social-btn"'));
        assertSame(renderedCounts($data)['cards'], substr_count($html, 'class="link-card'));
        assertContains('data-theme-default="system"', $html);
        assertNotContains('fonts.googleapis.com', $html);
        assertNotContains('fonts.gstatic.com', $html);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('renders the russian page', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $html = rendererFor($root)->render('ru');

        assertContains('<html lang="ru"', $html);
        assertContains(e($data['profile']['ru']['name']), $html);
        assertContains(e($data['sections'][0]['items'][0]['title']['ru']), $html);
        assertContains(e($data['sections'][3]['label']['ru']), $html);
        assertContains(e($data['sections'][4]['items'][0]['title']['ru']), $html);
        assertContains('id="langToggle"', $html);
        assertContains('href="/"', $html);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('uses visually hidden headings when a section has no label', function (): void {
    $root = makeWorkspace();

    try {
        $html = rendererFor($root)->render('en');
        assertContains('<h2 class="sr-only">Primary links</h2>', $html);
        assertContains('<h2 class="sr-only">Contact</h2>', $html);
        assertContains('<h2 class="section-title">Projects</h2>', $html);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('escapes user content', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $data['sections'][1]['items'][0]['title']['en'] = '<script>alert(1)</script>';
        $data['profile']['en']['bio'] = '"quoted" & <b>bold</b>';
        $html = rendererFor($root, $data)->render('en');

        assertNotContains('<script>alert(1)</script>', $html);
        assertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        assertContains('&quot;quoted&quot; &amp; &lt;b&gt;bold&lt;/b&gt;', $html);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('skips hidden items', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $hidden = $data['sections'][3]['items'][1];
        $data['sections'][3]['items'][1]['visible'] = false;
        $html = rendererFor($root, $data)->render('en');

        assertNotContains('>' . e($hidden['title']['en']) . '<', $html);
        assertNotContains(e($hidden['href']), $html);
        assertSame(renderedCounts($data)['socials'], substr_count($html, 'class="social-btn"'));
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('skips a section whose items are all hidden', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();

        foreach ($data['sections'][1]['items'] as &$item) {
            $item['visible'] = false;
        }

        unset($item);

        $html = rendererFor($root, $data)->render('en');

        assertNotContains('>Projects<', $html);
        assertNotContains('>Projects</h2>', $html);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('renders logos as images and icons as svg', function (): void {
    $root = makeWorkspace();

    try {
        $logo = seedData()['sections'][0]['items'][1]['logo'];
        $html = rendererFor($root)->render('en');
        assertContains('src="' . e(asset($logo)) . '"', $html);
        assertContains('class="link-logo"', $html);
        assertContains('class="icon-brand"', $html);
        assertContains('class="icon-ui"', $html);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('renders a social item with a logo instead of an icon', function (): void {
    $root = makeWorkspace();

    try {
        $logo = seedLogo();
        $data = seedData();
        unset($data['sections'][3]['items'][2]['icon']);
        $data['sections'][3]['items'][2]['logo'] = $logo;

        $html = rendererFor($root, $data)->render('en');

        assertContains('class="social-btn"', $html);
        assertContains('src="' . e(asset($logo)) . '"', $html);
        assertNotContains('Undefined array key', $html);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('honours the section layout', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $data['sections'][3]['layout'] = 'social';

        $html = rendererFor($root, $data)->render('en');

        assertSame(renderedCounts($data)['socials'], substr_count($html, 'class="social-btn"'));
        assertSame(renderedCounts($data)['cards'], substr_count($html, 'class="link-card'));
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('omits an empty subtitle', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $data['sections'][0]['items'][0]['subtitle'] = ['en' => '', 'ru' => ''];

        $expected = 0;

        foreach ($data['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if ($item['visible'] && ($item['subtitle']['en'] ?? '') !== '') {
                    $expected++;
                }
            }
        }

        $html = rendererFor($root, $data)->render('en');

        assertSame($expected, substr_count($html, 'class="link-subtitle"'));
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('footer never starts in the future', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $data['settings']['footerStart'] = 3000;
        $html = rendererFor($root, $data)->render('en');

        assertContains('&copy; 3000 - 3000', $html);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('card classes follow the item type', function (): void {
    $root = makeWorkspace();

    try {
        $html = rendererFor($root)->render('en');
        assertContains('link-card featured', $html);
        assertContains('link-card project-card', $html);
        assertContains('link-card email-card', $html);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('email block carries the address to copy', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $html = rendererFor($root)->render('en');
        assertContains('data-email="' . e($data['settings']['email']) . '"', $html);
        assertContains('aria-label="' . e($data['ui']['en']['copyEmail']) . '"', $html);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('injects GA4 only when a measurement ID is set', function (): void {
    $root = makeWorkspace();

    try {
        $off = rendererFor($root)->render('en');

        assertNotContains('googletagmanager.com', $off);
        assertContains('data-ga4=""', $off);

        $data = seedData();
        $data['settings']['ga4'] = 'G-ABCD123456';
        $on = rendererFor($root, $data)->render('en');

        assertContains('data-ga4="G-ABCD123456"', $on);
        assertContains('rel="preconnect" href="https://www.googletagmanager.com"', $on);
        assertNotContains('gtag(', $on);
        assertNotContains('googletagmanager.com/gtag/js', $on);
    } finally {
        removeWorkspace($root);
    }
});
