<?php
declare(strict_types=1);

use Bio\Cache;

Harness::group('Cache');

Harness::test('misses when nothing is cached', function (): void {
    $dir = sys_get_temp_dir() . '/bio-cache-' . bin2hex(random_bytes(4));

    try {
        assertSame(null, (new Cache($dir, 'key'))->get('en'));
    } finally {
        removeWorkspace($dir);
    }
});

Harness::test('stores and returns rendered html', function (): void {
    $dir = sys_get_temp_dir() . '/bio-cache-' . bin2hex(random_bytes(4));

    try {
        $cache = new Cache($dir, 'key');
        $cache->put('en', '<html>en</html>');
        assertSame('<html>en</html>', $cache->get('en'));
        assertTrue(is_file($dir . '/en.key'));
    } finally {
        removeWorkspace($dir);
    }
});

Harness::test('misses when the key changed', function (): void {
    $dir = sys_get_temp_dir() . '/bio-cache-' . bin2hex(random_bytes(4));

    try {
        (new Cache($dir, 'old'))->put('en', '<html>');
        assertSame(null, (new Cache($dir, 'new'))->get('en'));
    } finally {
        removeWorkspace($dir);
    }
});

Harness::test('misses when the key file is gone', function (): void {
    $dir = sys_get_temp_dir() . '/bio-cache-' . bin2hex(random_bytes(4));

    try {
        $cache = new Cache($dir, 'key');
        $cache->put('en', '<html>');
        unlink($dir . '/en.key');
        assertSame(null, $cache->get('en'));
    } finally {
        removeWorkspace($dir);
    }
});

Harness::test('creates its directory on demand', function (): void {
    $dir = sys_get_temp_dir() . '/bio-cache-' . bin2hex(random_bytes(4)) . '/nested';

    try {
        (new Cache($dir, 'key'))->put('ru', '<html>');
        assertSame('<html>', (new Cache($dir, 'key'))->get('ru'));
    } finally {
        removeWorkspace(dirname($dir));
    }
});

Harness::test('throws when the cache file cannot be written', function (): void {
    $dir = sys_get_temp_dir() . '/bio-cache-' . bin2hex(random_bytes(4));

    try {
        mkdir($dir . '/en.html', 0775, true);
        assertThrows(RuntimeException::class, static fn (): mixed => (new Cache($dir, 'key'))->put('en', 'x'), 'cache file');
    } finally {
        removeWorkspace($dir);
    }
});

Harness::test('returns null for an empty cache file', function (): void {
    $dir = sys_get_temp_dir() . '/bio-cache-' . bin2hex(random_bytes(4));

    try {
        $cache = new Cache($dir, 'key');
        $cache->put('en', '<html>');
        file_put_contents($dir . '/en.html', '');

        assertSame(null, $cache->get('en'));
    } finally {
        removeWorkspace($dir);
    }
});

Harness::test('flushes every artefact', function (): void {
    $dir = sys_get_temp_dir() . '/bio-cache-' . bin2hex(random_bytes(4));

    try {
        (new Cache($dir, 'key'))->put('en', '<html>');
        (new Cache($dir, 'key'))->put('ru', '<html>');
        file_put_contents($dir . '/leftover.tmp', 'x');
        Cache::flush($dir);
        assertSame([], glob($dir . '/*.html') ?: []);
        assertSame([], glob($dir . '/*.key') ?: []);
        assertSame([], glob($dir . '/*.tmp') ?: []);
    } finally {
        removeWorkspace($dir);
    }
});

Harness::test('key follows the revision and file contents', function (): void {
    $root = makeWorkspace();

    try {
        $file = $root . '/styles.css';
        assertNotSame(
            Cache::keyFor(['rev' => 1], []),
            Cache::keyFor(['rev' => 2], [])
        );

        $before = Cache::keyFor(['rev' => 1], [$file, '/nope/missing.css']);
        file_put_contents($file, '/* changed fixture */');
        clearstatcache();
        $after = Cache::keyFor(['rev' => 1], [$file, '/nope/missing.css']);

        assertNotSame($before, $after);
        assertSame($after, Cache::keyFor(['rev' => 1], [$file, '/nope/missing.css']));
    } finally {
        removeWorkspace($root);
    }
});
