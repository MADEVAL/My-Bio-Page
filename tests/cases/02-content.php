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
    ['siteUrl is not https', ['settings', 'siteUrl'], 'http://bio.leonidov.dev'],
    ['siteUrl is malformed', ['settings', 'siteUrl'], 'https://not a url'],
    ['email is invalid', ['settings', 'email'], 'nope'],
    ['themeDefault is unknown', ['settings', 'themeDefault'], 'blue'],
    ['footerStart is not an integer', ['settings', 'footerStart'], '2016'],
    ['lastmod has the wrong format', ['settings', 'lastmod'], '08.11.2026'],
    ['worksFor is not an object', ['settings', 'worksFor'], 'GLOBUS'],
    ['worksFor has no url', ['settings', 'worksFor'], ['name' => 'GLOBUS']],
    ['meta.en is missing', ['meta', 'en'], null],
    ['meta.en.title is empty', ['meta', 'en', 'title'], ''],
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
    ['item icon is unknown', ['sections', 0, 'items', 0, 'icon'], 'chrome'],
    ['item logo escapes assets/', ['sections', 0, 'items', 0, 'logo'], '../../etc/passwd'],
    ['item order is zero', ['sections', 0, 'items', 0, 'order'], 0],
    ['item visible is not a boolean', ['sections', 0, 'items', 0, 'visible'], 1],
    ['item title is empty', ['sections', 0, 'items', 0, 'title', 'en'], ''],
    ['item subtitle is not an object', ['sections', 0, 'items', 0, 'subtitle'], 'x'],
    ['ga4 is not a measurement ID', ['settings', 'ga4'], 'UA-123456'],
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

        $backups = glob($root . '/content/backups/site-*.json') ?: [];
        assertCount(1, $backups);
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
        mkdir($root . '/content/backups', 0775, true);

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
