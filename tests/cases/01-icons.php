<?php
declare(strict_types=1);

use Bio\Icons;

Harness::group('Icons');

Harness::test('every registered icon renders an svg', function (): void {
    $keys = Icons::keys();
    assertCount(16, $keys);

    foreach ($keys as $key) {
        assertTrue(Icons::has($key), $key);
        assertContains('viewBox="0 0 24 24"', Icons::render($key), $key);
        assertContains('aria-hidden="true"', Icons::render($key), $key);
    }
});

Harness::test('unknown icon is not registered', function (): void {
    assertFalse(Icons::has('nope'));
});

Harness::test('icon families are reported', function (): void {
    assertSame('brand', Icons::type('telegram'));
    assertSame('ui', Icons::type('globe'));
});

Harness::test('rendering an unknown icon throws', function (): void {
    assertThrows(InvalidArgumentException::class, static fn (): string => Icons::type('nope'), 'Unknown icon');
    assertThrows(InvalidArgumentException::class, static fn (): string => Icons::render('nope'), 'Unknown icon');
});

Harness::test('extra classes are appended', function (): void {
    assertContains('class="icon-ui link-arrow"', Icons::render('arrow-right', 'link-arrow'));
    assertContains('class="icon-brand"', Icons::render('x'));
});
