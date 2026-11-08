<?php
declare(strict_types=1);

namespace Bio;

use RuntimeException;

/**
 * Rendered HTML on disk. The key folds in the content revision, the template,
 * the public assets and every PHP source file, so a deploy invalidates itself
 * without anyone having to run a command on the server.
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

    public function etag(string $lang = ''): string
    {
        return 'W/"' . sha1($this->key . '|' . $lang) . '"';
    }

    public static function keyFor(array $data, array $files): string
    {
        $parts = [(string) $data['rev']];

        foreach ($files as $file) {
            $parts[] = is_file($file) ? (string) filemtime($file) : '0';
        }

        return sha1(implode('|', $parts));
    }

    private function write(string $path, string $data): void
    {
        $tmp = $path . '.tmp';
        $handle = @fopen($tmp, 'xb');

        if ($handle === false) {
            throw new RuntimeException('Cannot write cache file');
        }

        $written = fwrite($handle, $data);
        $flushed = fflush($handle);
        fclose($handle);

        if ($written !== strlen($data) || $flushed === false || !@rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('Cannot write cache file');
        }
    }
}
