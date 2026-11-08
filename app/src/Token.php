<?php
declare(strict_types=1);

namespace Bio;

/**
 * Lazily provisions the MCP token.
 *
 * The file lives in the private application directory, one level above the
 * public document root, so a plain web server cannot serve it. Creation is
 * automatic: no shell, no setup step, no manual key generation.
 */
final class Token
{
    public function __construct(private readonly string $file)
    {
    }

    public function ensure(): string
    {
        $existing = $this->read();

        if ($existing !== '') {
            return $existing;
        }

        $lock = @fopen($this->file . '.lock', 'c');

        if ($lock === false) {
            return $this->create();
        }

        flock($lock, LOCK_EX);

        try {
            $existing = $this->read();

            return $existing !== '' ? $existing : $this->create();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function read(): string
    {
        if (!is_file($this->file)) {
            return '';
        }

        @chmod($this->file, 0600);

        return trim((string) @file_get_contents($this->file));
    }

    private function create(): string
    {
        $dir = dirname($this->file);

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return '';
        }

        $token = bin2hex(random_bytes(32));
        $tmp = $this->file . '.tmp';
        $payload = $token . "\n";

        if (is_file($tmp)) {
            @unlink($tmp);
        }

        $oldUmask = umask(0077);
        $handle = @fopen($tmp, 'xb');
        umask($oldUmask);

        if ($handle === false) {
            return '';
        }

        $written = fwrite($handle, $payload);
        $flushed = fflush($handle);
        fclose($handle);

        if (
            $written !== strlen($payload)
            || $flushed === false
            || !@chmod($tmp, 0600)
            || !@rename($tmp, $this->file)
        ) {
            @unlink($tmp);

            return '';
        }

        return $token;
    }
}
