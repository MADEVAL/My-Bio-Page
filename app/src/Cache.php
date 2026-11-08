<?php
declare(strict_types=1);

namespace Bio;

use RuntimeException;

/**
 * Rendered HTML on disk. The key folds in the content revision and the
 * mtimes of every template asset, so a deploy invalidates itself without
 * anyone having to run a command on the server.
 */
final class Cache
{
    public function __construct(
        private readonly string $dir,
        private readonly string $key,
    ) {
    }

    public function get(string $lang): ?string
    {
        $html = $this->dir . '/' . $lang . '.html';
        $key = $this->dir . '/' . $lang . '.key';

        if (!is_file($html) || !is_file($key)) {
            return null;
        }

        if (trim((string) file_get_contents($key)) !== $this->key) {
            return null;
        }

        return (string) file_get_contents($html);
    }

    public function put(string $lang, string $html): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Cannot create cache directory: {$this->dir}");
        }

        if (file_put_contents($this->dir . '/' . $lang . '.html', $html) === false) {
            throw new RuntimeException('Cannot write cache file');
        }

        file_put_contents($this->dir . '/' . $lang . '.key', $this->key);
    }

    public static function flush(string $dir): void
    {
        foreach (glob($dir . '/*.html') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($dir . '/*.key') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function etag(): string
    {
        return 'W/"' . sha1($this->key) . '"';
    }

    public static function keyFor(array $data, array $files): string
    {
        $parts = [(string) $data['rev']];

        foreach ($files as $file) {
            $parts[] = is_file($file) ? (string) filemtime($file) : '0';
        }

        return sha1(implode('|', $parts));
    }
}
