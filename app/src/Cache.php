<?php
declare(strict_types=1);

namespace Bio;

use RuntimeException;

/**
 * Rendered HTML on disk. The key folds in the content revision, the template,
 * the public assets and every PHP source file, using content hashes, so a
 * deploy or out-of-band edit invalidates itself without a command on the server.
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

        // publish may unlink the key between is_file() and the read.
        if (trim((string) @file_get_contents($key)) !== $this->key) {
            return null;
        }

        $body = @file_get_contents($html);

        return is_string($body) && $body !== '' ? $body : null;
    }

    public function put(string $lang, string $html): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Cannot create cache directory: {$this->dir}");
        }

        $this->write($this->dir . '/' . $lang . '.html', $html);
        $this->write($this->dir . '/' . $lang . '.key', $this->key);
    }

    public static function flush(string $dir): void
    {
        foreach (glob($dir . '/*.html') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($dir . '/*.key') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($dir . '/*.tmp') ?: [] as $file) {
            @unlink($file);
        }
    }

    public static function keyFor(array $data, array $files): string
    {
        $parts = [(string) $data['rev']];

        foreach ($files as $file) {
            $hash = is_file($file) ? @hash_file('sha1', $file) : false;
            $parts[] = is_string($hash) ? $hash : '0';
        }

        return sha1(implode('|', $parts));
    }

    private function write(string $path, string $data): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $written = @file_put_contents($tmp, $data);

        if ($written !== strlen($data) || !@rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('Cannot write cache file');
        }
    }
}
