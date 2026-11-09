<?php
declare(strict_types=1);

use Bio\Content;

function validator(): Content
{
    return new Content('/nonexistent/a.json', '/nonexistent/b.json', '/nonexistent/c', '/nonexistent/d');
}

Harness::group('Content: validation');

Harness::test('seed content is valid', function (): void {
    $data = seedData();
    assertSame($data, validator()->validate($data));
});

$matrix = [
    ['rev is zero', ['rev'], 0],
    ['rev is a string', ['rev'], '1'],
    ['settings is not an object', ['settings'], 'x'],
    ['siteUrl is empty', ['settings', 'siteUrl'], ''],
    ['siteUrl is not https', ['settings', 'siteUrl'], 'http://example.com'],
    ['siteUrl is malformed', ['settings', 'siteUrl'], 'https://not a url'],
    ['siteUrl has a query', ['settings', 'siteUrl'], 'https://example.com/?x=1'],
    ['siteUrl has a fragment', ['settings', 'siteUrl'], 'https://example.com/#top'],
    ['email is invalid', ['settings', 'email'], 'nope'],
    ['personUrl is invalid', ['settings', 'personUrl'], 'javascript:alert(1)'],
    ['photo is unsafe', ['settings', 'photo'], 'javascript:alert(1)'],
    ['themeDefault is unknown', ['settings', 'themeDefault'], 'blue'],
    ['footerStart is not an integer', ['settings', 'footerStart'], '2016'],
    ['footerStart is out of range', ['settings', 'footerStart'], 1899],
    ['lastmod has the wrong format', ['settings', 'lastmod'], '08.11.2026'],
    ['lastmod has an impossible date', ['settings', 'lastmod'], '2026-02-30'],
    ['worksFor is not an object', ['settings', 'worksFor'], 'Acme'],
    ['worksFor has no url', ['settings', 'worksFor'], ['name' => 'Acme']],
    ['worksFor has an empty name', ['settings', 'worksFor'], ['name' => '', 'url' => 'https://x.test']],
    ['worksFor has an invalid url', ['settings', 'worksFor'], ['name' => 'X', 'url' => 'nope']],
    ['meta.en is missing', ['meta', 'en'], null],
    ['meta.en.title is empty', ['meta', 'en', 'title'], ''],
    ['meta.en.title is too long', ['meta', 'en', 'title'], str_repeat('a', 2001)],
    ['meta.en.title exceeds the SEO limit', ['meta', 'en', 'title'], str_repeat('a', 71)],
    ['meta.en.description is too short', ['meta', 'en', 'description'], str_repeat('a', 49)],
    ['meta.en.description is too long', ['meta', 'en', 'description'], str_repeat('a', 161)],
    ['ogImage must be raster', ['settings', 'ogImage'], 'assets/favicon.svg'],
    ['profile.en is missing', ['profile', 'en'], null],
    ['profile.ru.name is empty', ['profile', 'ru', 'name'], ''],
    ['ui.en is missing', ['ui', 'en'], null],
    ['ui.ru.controls is empty', ['ui', 'ru', 'controls'], ''],
    ['sections is empty', ['sections'], []],
    ['sections is not a list', ['sections'], ['primary' => []]],
    ['section is not an object', ['sections', 0], 'primary'],
    ['section id is not a slug', ['sections', 0, 'id'], 'Primary'],
    ['section id is duplicated', ['sections', 1, 'id'], 'primary'],
    ['label is not an object', ['sections', 0, 'label'], 'Primary'],
    ['label misses a language', ['sections', 0, 'label'], ['en' => 'Primary']],
    ['srLabel is null', ['sections', 0, 'srLabel'], ['en' => null, 'ru' => null]],
    ['layout is unknown', ['sections', 0, 'layout'], 'list'],
    ['section order is zero', ['sections', 0, 'order'], 0],
    ['items is not a list', ['sections', 0, 'items'], ['a' => 1]],
    ['item is not an object', ['sections', 0, 'items', 0], 'telegram'],
    ['item id is not a slug', ['sections', 0, 'items', 0, 'id'], '1telegram'],
    ['item type is unknown', ['sections', 0, 'items', 0, 'type'], 'button'],
    ['item href uses a bad scheme', ['sections', 0, 'items', 0, 'href'], 'ftp://example.com'],
    ['item href is not a string', ['sections', 0, 'items', 0, 'href'], 42],
    ['item href is too long', ['sections', 0, 'items', 0, 'href'], 'https://example.com/' . str_repeat('a', 2050)],
    ['email item href is not mailto', ['sections', 4, 'items', 0, 'href'], 'https://example.com'],
    ['email item href does not match settings.email', ['sections', 4, 'items', 0, 'href'], 'mailto:other@example.com'],
    ['item icon is unknown', ['sections', 0, 'items', 0, 'icon'], 'chrome'],
    ['item logo escapes assets/', ['sections', 0, 'items', 0, 'logo'], '../../etc/passwd'],
    ['item order is zero', ['sections', 0, 'items', 0, 'order'], 0],
    ['item visible is not a boolean', ['sections', 0, 'items', 0, 'visible'], 1],
    ['item title is empty', ['sections', 0, 'items', 0, 'title', 'en'], ''],
    ['item title is too long', ['sections', 0, 'items', 0, 'title', 'en'], str_repeat('a', 2001)],
    ['item subtitle is not an object', ['sections', 0, 'items', 0, 'subtitle'], 'x'],
    ['ga4 is not a measurement ID', ['settings', 'ga4'], 'UA-123456'],
    ['ga4 is too short', ['settings', 'ga4'], 'G-ABCDE'],
    ['ga4 is not a string', ['settings', 'ga4'], 123],
];

foreach ($matrix as [$label, $path, $value]) {
    Harness::test('rejects: ' . $label, function () use ($path, $value): void {
        $data = withPath(seedData(), $path, $value);
        assertThrows(InvalidArgumentException::class, static fn (): array => validator()->validate($data));
    });
}

Harness::test('rejects: item with both icon and logo', function (): void {
    $data = withPath(seedData(), ['sections', 0, 'items', 0, 'logo'], 'assets/x.png');
    assertThrows(InvalidArgumentException::class, static fn (): array => validator()->validate($data), 'exactly one of icon or logo');
});

Harness::test('rejects: item without icon or logo', function (): void {
    $data = seedData();
    unset($data['sections'][0]['items'][0]['icon']);
    assertThrows(InvalidArgumentException::class, static fn (): array => validator()->validate($data), 'exactly one of icon or logo');
});

Harness::test('rejects: duplicate item ids across the tree', function (): void {
    $data = seedData();
    $data['sections'][1]['items'][0]['id'] = 'telegram';
    assertThrows(InvalidArgumentException::class, static fn (): array => validator()->validate($data), 'duplicate item id');
});

Harness::test('rejects: more than one email item', function (): void {
    $data = seedData();
    $email = $data['sections'][4]['items'][0];
    $email['id'] = 'email2';
    $email['order'] = 2;
    $data['sections'][4]['items'][] = $email;
    assertThrows(InvalidArgumentException::class, static fn (): array => validator()->validate($data), 'more than one email item');
});

Harness::test('rejects: too many sections', function (): void {
    $data = seedData();
    $section = $data['sections'][0];
    $section['items'] = [];
    $sections = [];

    for ($i = 1; $i <= 51; $i++) {
        $section['id'] = 'section-' . $i;
        $sections[] = $section;
    }

    $data['sections'] = $sections;
    assertThrows(InvalidArgumentException::class, static fn (): array => validator()->validate($data), 'more than 50 sections');
});

Harness::test('rejects: too many items', function (): void {
    $data = seedData();
    $base = $data['sections'][0]['items'][0];
    $items = [];

    for ($i = 1; $i <= 501; $i++) {
        $item = $base;
        $item['id'] = 'item-' . $i;
        $items[] = $item;
    }

    $data['sections'][0]['items'] = $items;
    assertThrows(InvalidArgumentException::class, static fn (): array => validator()->validate($data), 'more than 500 items');
});

Harness::test('rejects: content over the byte limit', function (): void {
    $data = seedData();
    $base = $data['sections'][0]['items'][0];
    $long = str_repeat('a', 2000);
    $items = [];

    for ($i = 1; $i <= 300; $i++) {
        $item = $base;
        $item['id'] = 'long-' . $i;
        $item['title'] = ['en' => $long, 'ru' => $long];
        $item['subtitle'] = ['en' => $long, 'ru' => $long];
        $items[] = $item;
    }

    $data['sections'][0]['items'] = $items;
    assertThrows(InvalidArgumentException::class, static fn (): array => validator()->validate($data), '1 MiB');
});

Harness::test('rejects: a missing logo file', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $data['sections'][1]['items'][0]['logo'] = 'assets/nope.png';
        assertThrows(InvalidArgumentException::class, static fn (): array => makeContent($root)->validate($data), 'existing asset');
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('rejects: a missing photo file', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $data['settings']['photo'] = 'assets/nope.jpg';
        assertThrows(InvalidArgumentException::class, static fn (): array => makeContent($root)->validate($data), 'existing asset');
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('rejects: an OG image with the wrong dimensions', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $data['settings']['ogImage'] = 'assets/photo-opt.jpg';
        assertThrows(InvalidArgumentException::class, static fn (): array => makeContent($root)->validate($data), '1200x630');
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('accepts: meta title and description at the SEO limits', function (): void {
    $data = seedData();
    $data['meta']['en']['title'] = str_repeat('a', 70);
    $data['meta']['en']['description'] = str_repeat('a', 50);
    $data['meta']['ru']['title'] = str_repeat('б', 70);
    $data['meta']['ru']['description'] = str_repeat('б', 160);

    assertSame($data, validator()->validate($data));
});

Harness::test('accepts: null labels and empty subtitles', function (): void {
    $data = seedData();
    $data['sections'][0]['label'] = ['en' => null, 'ru' => null];
    $data['sections'][0]['items'][1]['subtitle'] = ['en' => '', 'ru' => ''];
    assertSame($data, validator()->validate($data));
});

Harness::test('accepts: a GA4 measurement ID', function (): void {
    $data = withPath(seedData(), ['settings', 'ga4'], 'G-ABCD123456');
    assertSame($data, validator()->validate($data));
});

Harness::group('Content: loading');

Harness::test('falls back to the seed when there is no site.json', function (): void {
    $root = makeWorkspace();

    try {
        $data = makeContent($root)->load();
        assertSame(1, $data['rev']);
        assertSame('primary', $data['sections'][0]['id']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('sorts sections and items by order and fills subtitles', function (): void {
    $root = makeWorkspace();

    try {
        $data = seedData();
        $data['sections'][0]['order'] = 9;
        $data['sections'][1]['order'] = 1;
        $data['sections'][0]['items'][0]['order'] = 5;
        $data['sections'][0]['items'][1]['order'] = 2;
        unset($data['sections'][0]['items'][0]['subtitle']);
        writeSite($root, $data);

        $loaded = makeContent($root)->load();
        assertSame('projects', $loaded['sections'][0]['id']);
        assertSame('primary', $loaded['sections'][4]['id']);
        assertSame('globus', $loaded['sections'][4]['items'][0]['id']);
        assertSame('telegram', $loaded['sections'][4]['items'][1]['id']);
        assertSame(['en' => '', 'ru' => ''], $loaded['sections'][4]['items'][1]['subtitle']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('throws when the JSON is broken', function (): void {
    $root = makeWorkspace();

    try {
        file_put_contents($root . '/content/site.json', '{"rev":');
        assertThrows(RuntimeException::class, static fn (): array => makeContent($root)->load(), 'not valid JSON');
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('throws when no content file is readable', function (): void {
    $root = makeWorkspace();

    try {
        unlink($root . '/content/site.seed.json');
        assertThrows(RuntimeException::class, static fn (): array => makeContent($root)->load(), 'not readable');
    } finally {
        removeWorkspace($root);
    }
});

Harness::group('Content: writing');

Harness::test('mutate bumps the revision, writes a backup and an audit line', function (): void {
    $root = makeWorkspace();

    try {
        $content = makeContent($root);
        $content->mutate('test-actor', 1, static fn (array $d): array => $d);
        $next = $content->mutate('test-actor', 2, static function (array $d): array {
            $d['settings']['footerStart'] = 2020;

            return $d;
        });

        assertSame(3, $next['rev']);
        assertSame(2020, $next['settings']['footerStart']);
        assertContains('"rev": 3', (string) file_get_contents($root . '/content/site.json'));
        assertContains('"actor":"test-actor"', (string) file_get_contents($root . '/content/.audit.jsonl'));
        assertContains('"action":"mutate"', (string) file_get_contents($root . '/content/.audit.jsonl'));

        $backups = glob($root . '/content/backups/site-*.json') ?: [];
        assertCount(2, $backups);
        assertTrue(is_file($root . '/content/backups/site-rev001.json'));
        assertTrue(is_file($root . '/content/backups/site-rev002.json'));
        assertSame(3, $content->load()['rev']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('rejects a stale revision and leaves the file alone', function (): void {
    $root = makeWorkspace();

    try {
        $content = makeContent($root);
        $content->mutate('t', null, static fn (array $d): array => $d);
        $before = (string) file_get_contents($root . '/content/site.json');

        assertThrows(DomainException::class, static fn (): array => $content->mutate('t', 99, static fn (array $d): array => $d), 'Revision conflict');
        assertSame($before, (string) file_get_contents($root . '/content/site.json'));
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('rejects invalid content before touching the disk', function (): void {
    $root = makeWorkspace();

    try {
        $content = makeContent($root);
        $content->mutate('t', null, static fn (array $d): array => $d);

        assertThrows(InvalidArgumentException::class, static fn (): array => $content->mutate('t', null, static function (array $d): array {
            $d['profile']['en']['bio'] = '';

            return $d;
        }));

        assertSame(2, $content->load()['rev']);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('prunes old backups', function (): void {
    $root = makeWorkspace();

    try {
        $content = makeContent($root);
        $content->mutate('t', null, static fn (array $d): array => $d);

        if (!is_dir($root . '/content/backups')) {
            mkdir($root . '/content/backups', 0775, true);
        }

        for ($i = 1; $i <= 25; $i++) {
            file_put_contents(sprintf('%s/content/backups/site-20200101-0000%02d-rev%03d.json', $root, $i, $i), '{}');
        }

        $content->mutate('t', null, static fn (array $d): array => $d);
        assertCount(20, glob($root . '/content/backups/site-*.json') ?: []);
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('throws when the backup directory cannot be created', function (): void {
    $root = makeWorkspace();

    try {
        $content = makeContent($root);
        $content->mutate('t', null, static fn (array $d): array => $d);

        file_put_contents($root . '/content/blocked', 'x');
        $broken = makeContent($root, null, $root . '/content/blocked');

        assertThrows(RuntimeException::class, static fn (): array => $broken->mutate('t', null, static fn (array $d): array => $d), 'backup directory');
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('throws when a backup cannot be copied', function (): void {
    $root = makeWorkspace();

    try {
        $content = makeContent($root);
        $content->mutate('t', null, static fn (array $d): array => $d);

        mkdir($root . '/content/backups/site-rev002.json', 0775, true);

        assertThrows(
            RuntimeException::class,
            static fn (): array => $content->mutate('t', null, static fn (array $d): array => $d),
            'backup file'
        );
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('throws when the target file cannot be replaced', function (): void {
    $root = makeWorkspace();

    try {
        mkdir($root . '/content/site.json', 0775, true);
        assertThrows(RuntimeException::class, static fn (): array => makeContent($root)->mutate('t', null, static fn (array $d): array => $d), 'write content file');
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('throws when content cannot be encoded as JSON', function (): void {
    $root = makeWorkspace();

    try {
        $content = makeContent($root);
        assertThrows(RuntimeException::class, static fn (): array => $content->mutate('t', null, static function (array $d): array {
            $d['profile']['en']['bio'] = "\xFF";

            return $d;
        }), 'encode content');
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('throws when the lock file is not writable', function (): void {
    $root = makeWorkspace();

    try {
        mkdir($root . '/content/site.json.lock', 0775, true);
        assertThrows(RuntimeException::class, static fn (): array => makeContent($root)->mutate('t', null, static fn (array $d): array => $d), 'lock');
    } finally {
        removeWorkspace($root);
    }
});
