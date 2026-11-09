<?php
declare(strict_types=1);

const APP_DIR = __DIR__ . '/../../app';
const PUBLIC_DIR = __DIR__ . '/../../public';

/** Create an isolated copy of the app skeleton for one test. */
function makeWorkspace(): string
{
    $root = sys_get_temp_dir() . '/bio-' . bin2hex(random_bytes(6));

    foreach (['content', 'templates', 'cache', 'public/assets'] as $dir) {
        mkdir($root . '/' . $dir, 0775, true);
    }

    copy(APP_DIR . '/templates/page.php', $root . '/templates/page.php');
    copy(APP_DIR . '/content/site.seed.json', $root . '/content/site.seed.json');
    file_put_contents($root . '/styles.css', '/* test fixture */');
    file_put_contents($root . '/script.js', '/* test fixture */');
    file_put_contents($root . '/public/styles.css', '/* test fixture */');
    file_put_contents($root . '/public/script.js', '/* test fixture */');

    foreach ([
        'photo-opt.jpg',
        'OG-opt.jpg',
        'GLOBUS_WHITE-opt.png',
        'bb_mini-opt.png',
        'apple-touch-icon.png',
        'favicon.svg',
    ] as $asset) {
        copy(PUBLIC_DIR . '/assets/' . $asset, $root . '/public/assets/' . $asset);
    }

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

function seedSiteUrl(): string
{
    return rtrim(seedData()['settings']['siteUrl'], '/');
}

/** @return array<string, mixed> */
function seedItem(string $id): array
{
    foreach (seedData()['sections'] as $section) {
        foreach ($section['items'] as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
    }

    throw new RuntimeException('Seed item not found: ' . $id);
}

function seedLogo(): string
{
    foreach (seedData()['sections'] as $section) {
        foreach ($section['items'] as $item) {
            if (isset($item['logo'])) {
                return $item['logo'];
            }
        }
    }

    throw new RuntimeException('Seed has no logo asset');
}

/** @param array<string, mixed> $data */
function visibleItemCount(array $data): int
{
    $count = 0;

    foreach ($data['sections'] as $section) {
        foreach ($section['items'] as $item) {
            if ($item['visible']) {
                $count++;
            }
        }
    }

    return $count;
}

/** @return array{cards: int, socials: int} */
function renderedCounts(array $data): array
{
    $cards = 0;
    $socials = 0;

    foreach ($data['sections'] as $section) {
        $visible = array_values(array_filter(
            $section['items'],
            static fn (array $item): bool => $item['visible']
        ));

        if ($section['layout'] === 'social') {
            $socials += count($visible);
        } elseif ($section['layout'] === 'mixed') {
            foreach ($visible as $item) {
                $item['type'] === 'social' ? $socials++ : $cards++;
            }
        } else {
            $cards += count($visible);
        }
    }

    return ['cards' => $cards, 'socials' => $socials];
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
        $root . '/public',
    );
}

/** Where the auto-provisioned token lands: inside the private app directory. */
function tokenPath(string $root): string
{
    return $root . '/bio.token';
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
