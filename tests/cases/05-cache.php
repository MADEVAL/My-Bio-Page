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

Harness::test('flushes every artefact', function (): void {
    $dir = sys_get_temp_dir() . '/bio-cache-' . bin2hex(random_bytes(4));

    try {
        (new Cache($dir, 'key'))->put('en', '<html>');
        (new Cache($dir, 'key'))->put('ru', '<html>');
        Cache::flush($dir);
        assertSame([], glob($dir . '/*.html') ?: []);
        assertSame([], glob($dir . '/*.key') ?: []);
    } finally {
        removeWorkspace($dir);
    }
});

Harness::test('etag is weak and derived from the key', function (): void {
    $a = (new Cache('/tmp', 'one'))->etag();
    $b = (new Cache('/tmp', 'two'))->etag();

    assertMatches('~^W/"[0-9a-f]{40}"$~', $a);
    assertNotSame($a, $b);
});

Harness::test('key follows the revision and asset mtimes', function (): void {
    $root = makeWorkspace();

    try {
        $file = $root . '/styles.css';
        assertNotSame(
            Cache::keyFor(['rev' => 1], []),
            Cache::keyFor(['rev' => 2], [])
        );

        $before = Cache::keyFor(['rev' => 1], [$file, '/nope/missing.css']);
        touch($file, time() + 30);
        clearstatcache();
        $after = Cache::keyFor(['rev' => 1], [$file, '/nope/missing.css']);

        assertNotSame($before, $after);
        assertSame($after, Cache::keyFor(['rev' => 1], [$file, '/nope/missing.css']));
    } finally {
        removeWorkspace($root);
    }
});
