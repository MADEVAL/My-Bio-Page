<?php
declare(strict_types=1);

const APP_DIR = __DIR__ . '/../../app';

/** Create an isolated copy of the app skeleton for one test. */
function makeWorkspace(): string
{
    $root = sys_get_temp_dir() . '/bio-' . bin2hex(random_bytes(6));

    foreach (['content', 'templates', 'cache'] as $dir) {
        mkdir($root . '/' . $dir, 0775, true);
    }

    copy(APP_DIR . '/templates/page.php', $root . '/templates/page.php');
    copy(APP_DIR . '/content/site.seed.json', $root . '/content/site.seed.json');
    file_put_contents($root . '/styles.css', '/* test fixture */');
    file_put_contents($root . '/script.js', '/* test fixture */');

    return $root;
}

function removeWorkspace(string $root): void
{
    if (!is_dir($root)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($root);
}

/** A valid content tree straight from the seed file. */
function seedData(): array
{
    $raw = (string) file_get_contents(APP_DIR . '/content/site.seed.json');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new RuntimeException('Seed file is broken');
    }

    return $data;
}

function seedJson(): string
{
    return (string) file_get_contents(APP_DIR . '/content/site.seed.json');
}

function writeSite(string $root, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($root . '/content/site.json', $json . "\n");
}

function makeContent(string $root, ?string $file = null, ?string $backupDir = null): Bio\Content
{
    return new Bio\Content(
        $file ?? $root . '/content/site.json',
        $root . '/content/site.seed.json',
        $backupDir ?? $root . '/content/backups',
        $root . '/content/.audit.jsonl',
    );
}

/** Where the auto-provisioned token lands: next to the document root. */
function outsideTokenPath(string $root): string
{
    return dirname($root) . '/bio.token';
}

/** Copy of the seed with a single field replaced by path. */
function withPath(array $data, array $path, mixed $value): array
{
    $ref = &$data;

    foreach ($path as $key) {
        $ref = &$ref[$key];
    }

    $ref = $value;

    return $data;
}
