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
        $html = rendererFor($root)->render('en');

        assertContains('<html lang="en"', $html);
        assertContains('Yevhen Leonidov', $html);
        assertContains('Software Architect &amp; Developer', $html);
        assertContains('<title>Yevhen Leonidov | Software Architect</title>', $html);
        assertContains('rel="canonical" href="https://bio.leonidov.dev/"', $html);
        assertContains('class="social-grid"', $html);
        assertContains('class="email-wrapper"', $html);
        assertContains('id="copyEmail"', $html);
        assertContains('id="i18n"', $html);
        assertContains('hreflang="ru"', $html);
        assertContains('/styles.css?v=', $html);
        assertContains('/script.js?v=', $html);
        assertSame(15, anchors($html));
        assertSame(6, substr_count($html, 'class="social-btn"'));
        assertSame(8, substr_count($html, 'class="link-card'));
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
        $html = rendererFor($root)->render('ru');

        assertContains('<html lang="ru"', $html);
        assertContains('Евгений Леонидов', $html);
        assertContains('Телеграм-канал', $html);
        assertContains('Соцсети', $html);
        assertContains('Написать мне', $html);
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
        $data['sections'][3]['items'][1]['visible'] = false;
        $html = rendererFor($root, $data)->render('en');

        assertNotContains('>Facebook<', $html);
        assertNotContains('https://www.facebook.com/', $html);
        assertSame(6, substr_count($html, 'class="social-btn"'));
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('renders logos as images and icons as svg', function (): void {
    $root = makeWorkspace();

    try {
        $html = rendererFor($root)->render('en');
        assertContains('src="/assets/GLOBUS_WHITE.png"', $html);
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
        $data = seedData();
        unset($data['sections'][3]['items'][2]['icon']);
        $data['sections'][3]['items'][2]['logo'] = 'assets/bb_mini.png';

        $html = rendererFor($root, $data)->render('en');

        assertContains('class="social-btn"', $html);
        assertContains('src="/assets/bb_mini.png"', $html);
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

        assertSame(8, substr_count($html, 'class="social-btn"'));
        assertSame(6, substr_count($html, 'class="link-card'));
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('omits an empty subtitle', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $data['sections'][0]['items'][0]['subtitle'] = ['en' => '', 'ru' => ''];
        $html = rendererFor($root, $data)->render('en');

        assertSame(7, substr_count($html, 'class="link-subtitle"'));
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
        $html = rendererFor($root)->render('en');
        assertContains('data-email="leonidovev@gmail.com"', $html);
        assertContains('aria-label="Copy email address"', $html);
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
