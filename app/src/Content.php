<?php
declare(strict_types=1);

namespace Bio;

use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Single source of truth for the whole site.
 *
 * The JSON file is read on demand, validated before every write, backed up
 * before every write and written atomically under an exclusive lock.
 */
final class Content
{
    public const ITEM_TYPES = ['link', 'featured', 'project', 'social', 'email'];
    public const LAYOUTS = ['cards', 'social', 'mixed'];
    public const THEMES = ['system', 'dark', 'light'];

    private const LANGS = ['en', 'ru'];
    private const MAX_BACKUPS = 20;

    private ?array $data = null;

    public function __construct(
        private readonly string $file,
        private readonly string $seed,
        private readonly string $backupDir,
        private readonly string $auditFile,
    ) {
    }

    public function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $path = is_file($this->file) ? $this->file : $this->seed;
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("Content file is not readable: {$path}");
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Content is not valid JSON: ' . json_last_error_msg());
        }

        return $this->data = $this->normalize($this->validate($decoded));
    }

    /**
     * Read-modify-write cycle with optimistic concurrency control.
     *
     * @param callable(array): array $mutator
     */
    public function mutate(string $actor, ?int $expectedRev, callable $mutator): array
    {
        $handle = $this->lock();

        try {
            $this->data = null;
            $current = $this->load();

            if ($expectedRev !== null && $expectedRev !== $current['rev']) {
                throw new DomainException(
                    "Revision conflict: expected {$expectedRev}, current is {$current['rev']}"
                );
            }

            $next = $mutator($current);
            $next['rev'] = $current['rev'] + 1;
            $next['settings']['lastmod'] = date('Y-m-d');
            $next = $this->normalize($this->validate($next));

            $this->backup($current['rev']);
            $this->write($next);
            $this->audit($actor, $next['rev']);

            return $this->data = $next;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function validate(array $data): array
    {
        if (!is_int($data['rev'] ?? null) || $data['rev'] < 1) {
            $this->fail('rev', 'must be a positive integer');
        }

        $this->validateSettings($data['settings'] ?? null);
        $this->validateLanguages($data);
        $this->validateSections($data['sections'] ?? null);

        return $data;
    }

    private function validateSettings(mixed $settings): void
    {
        if (!is_array($settings)) {
            $this->fail('settings', 'must be an object');
        }

        foreach (['siteUrl', 'email', 'photo', 'ogImage', 'personUrl'] as $key) {
            if (!is_string($settings[$key] ?? null) || $settings[$key] === '') {
                $this->fail("settings.{$key}", 'must be a non-empty string');
            }
        }

        if (!str_starts_with($settings['siteUrl'], 'https://') || filter_var($settings['siteUrl'], FILTER_VALIDATE_URL) === false) {
            $this->fail('settings.siteUrl', 'must be an https URL');
        }

        if (filter_var($settings['email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('settings.email', 'must be a valid email address');
        }

        if (!in_array($settings['themeDefault'] ?? null, self::THEMES, true)) {
            $this->fail('settings.themeDefault', 'must be system, dark or light');
        }

        if (!is_int($settings['footerStart'] ?? null)) {
            $this->fail('settings.footerStart', 'must be an integer');
        }

        if (!is_string($settings['lastmod'] ?? null) || preg_match('~^\d{4}-\d{2}-\d{2}$~', $settings['lastmod']) !== 1) {
            $this->fail('settings.lastmod', 'must be YYYY-MM-DD');
        }

        $worksFor = $settings['worksFor'] ?? null;

        if (!is_array($worksFor) || !is_string($worksFor['name'] ?? null) || !is_string($worksFor['url'] ?? null)) {
            $this->fail('settings.worksFor', 'must contain name and url');
        }
    }

    private function validateLanguages(array $data): void
    {
        foreach (self::LANGS as $lang) {
            $meta = $data['meta'][$lang] ?? null;

            if (!is_array($meta)) {
                $this->fail("meta.{$lang}", 'must be an object');
            }

            foreach (['title', 'description', 'ogAlt', 'siteName', 'jobTitle', 'personName', 'alternateName'] as $key) {
                if (!is_string($meta[$key] ?? null) || $meta[$key] === '') {
                    $this->fail("meta.{$lang}.{$key}", 'must be a non-empty string');
                }
            }

            $profile = $data['profile'][$lang] ?? null;

            if (!is_array($profile)) {
                $this->fail("profile.{$lang}", 'must be an object');
            }

            foreach (['name', 'role', 'bio', 'alt'] as $key) {
                if (!is_string($profile[$key] ?? null) || $profile[$key] === '') {
                    $this->fail("profile.{$lang}.{$key}", 'must be a non-empty string');
                }
            }

            $ui = $data['ui'][$lang] ?? null;

            if (!is_array($ui)) {
                $this->fail("ui.{$lang}", 'must be an object');
            }

            foreach (['switchLang', 'copyEmail', 'themeToLight', 'themeToDark', 'copied', 'copyFailed', 'controls'] as $key) {
                if (!is_string($ui[$key] ?? null) || $ui[$key] === '') {
                    $this->fail("ui.{$lang}.{$key}", 'must be a non-empty string');
                }
            }
        }
    }

    private function validateSections(mixed $sections): void
    {
        if (!is_array($sections) || $sections === [] || !array_is_list($sections)) {
            $this->fail('sections', 'must be a non-empty list');
        }

        $sectionIds = [];

        foreach ($sections as $index => $section) {
            $path = "sections[{$index}]";

            if (!is_array($section)) {
                $this->fail($path, 'must be an object');
            }

            $id = $section['id'] ?? null;

            if (!is_string($id) || preg_match('~^[a-z][a-z0-9-]{0,40}$~', $id) !== 1) {
                $this->fail("{$path}.id", 'must be a lowercase slug');
            }

            if (isset($sectionIds[$id])) {
                $this->fail("{$path}.id", 'must be unique');
            }

            $sectionIds[$id] = true;

            $this->validateLocalizedField($section['label'] ?? null, "{$path}.label", true);
            $this->validateLocalizedField($section['srLabel'] ?? null, "{$path}.srLabel", false);

            if (!in_array($section['layout'] ?? null, self::LAYOUTS, true)) {
                $this->fail("{$path}.layout", 'must be cards, social or mixed');
            }

            if (!is_int($section['order'] ?? null) || $section['order'] < 1) {
                $this->fail("{$path}.order", 'must be a positive integer');
            }

            if (!is_array($section['items'] ?? null) || !array_is_list($section['items'])) {
                $this->fail("{$path}.items", 'must be a list');
            }

            $this->validateItems($section['items'], $path);
        }
    }

    private function validateItems(array $items, string $sectionPath): void
    {
        foreach ($items as $index => $item) {
            $path = "{$sectionPath}.items[{$index}]";

            if (!is_array($item)) {
                $this->fail($path, 'must be an object');
            }

            $id = $item['id'] ?? null;

            if (!is_string($id) || preg_match('~^[a-z][a-z0-9-]{0,40}$~', $id) !== 1) {
                $this->fail("{$path}.id", 'must be a lowercase slug');
            }

            if (!in_array($item['type'] ?? null, self::ITEM_TYPES, true)) {
                $this->fail("{$path}.type", 'is not a supported type');
            }

            $href = $item['href'] ?? null;

            if (!is_string($href) || preg_match('~^(https?://|mailto:)[^\s]+$~', $href) !== 1) {
                $this->fail("{$path}.href", 'must be an http(s) or mailto URL');
            }

            $hasIcon = isset($item['icon']);
            $hasLogo = isset($item['logo']);

            if ($hasIcon === $hasLogo) {
                $this->fail($path, 'must have exactly one of icon or logo');
            }

            if ($hasIcon && (!is_string($item['icon']) || !Icons::has($item['icon']))) {
                $this->fail("{$path}.icon", 'is not a known icon');
            }

            if ($hasLogo && (!is_string($item['logo']) || preg_match('~^assets/[A-Za-z0-9._-]+\.(png|jpg|jpeg|svg)$~', $item['logo']) !== 1)) {
                $this->fail("{$path}.logo", 'must be a path under assets/');
            }

            if (!is_int($item['order'] ?? null) || $item['order'] < 1) {
                $this->fail("{$path}.order", 'must be a positive integer');
            }

            if (!is_bool($item['visible'] ?? null)) {
                $this->fail("{$path}.visible", 'must be a boolean');
            }

            $this->validateLocalizedField($item['title'] ?? null, "{$path}.title", false);

            if (array_key_exists('subtitle', $item)) {
                $this->validateLocalizedField($item['subtitle'], "{$path}.subtitle", true, true);
            }
        }
    }

    private function validateLocalizedField(mixed $field, string $path, bool $allowNull, bool $allowEmpty = false): void
    {
        if (!is_array($field)) {
            $this->fail($path, 'must be an object with en and ru');
        }

        foreach (self::LANGS as $lang) {
            if (!array_key_exists($lang, $field)) {
                $this->fail("{$path}.{$lang}", 'is missing');
            }

            $value = $field[$lang];

            if ($allowNull && $value === null) {
                continue;
            }

            if (!is_string($value) || (!$allowEmpty && $value === '')) {
                $this->fail("{$path}.{$lang}", 'must be a non-empty string');
            }
        }
    }

    private function normalize(array $data): array
    {
        usort($data['sections'], static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        foreach ($data['sections'] as $sIndex => $section) {
            usort($section['items'], static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

            foreach ($section['items'] as $iIndex => $item) {
                $item['subtitle'] ??= ['en' => '', 'ru' => ''];
                $section['items'][$iIndex] = $item;
            }

            $data['sections'][$sIndex] = $section;
        }

        return $data;
    }

    private function backup(int $rev): void
    {
        if (!is_file($this->file)) {
            return;
        }

        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0775, true) && !is_dir($this->backupDir)) {
            throw new RuntimeException("Cannot create backup directory: {$this->backupDir}");
        }

        $name = sprintf('site-%s-rev%03d.json', date('Ymd-His'), $rev);
        @copy($this->file, $this->backupDir . '/' . $name);
        $this->pruneBackups();
    }

    private function pruneBackups(): void
    {
        $files = glob($this->backupDir . '/site-*.json') ?: [];

        if (count($files) <= self::MAX_BACKUPS) {
            return;
        }

        sort($files);

        foreach (array_slice($files, 0, count($files) - self::MAX_BACKUPS) as $old) {
            @unlink($old);
        }
    }

    private function write(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('Cannot encode content: ' . json_last_error_msg());
        }

        $tmp = $this->file . '.tmp';

        if (@file_put_contents($tmp, $json . "\n") === false || !@rename($tmp, $this->file)) {
            throw new RuntimeException('Cannot write content file');
        }
    }

    private function audit(string $actor, int $rev): void
    {
        $line = json_encode([
            'at' => date(DATE_ATOM),
            'actor' => $actor,
            'rev' => $rev,
        ], JSON_UNESCAPED_SLASHES) . "\n";

        @file_put_contents($this->auditFile, $line, FILE_APPEND | LOCK_EX);
    }

    /** @return resource */
    private function lock()
    {
        $handle = @fopen($this->file . '.lock', 'c');

        if ($handle === false) {
            throw new RuntimeException('Cannot open content lock');
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Cannot acquire content lock');
        }

        return $handle;
    }

    private function fail(string $field, string $message): never
    {
        throw new InvalidArgumentException("{$field} {$message}");
    }
}
