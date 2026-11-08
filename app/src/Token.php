<?php
declare(strict_types=1);

namespace Bio;

/**
 * Lazily provisions the MCP token.
 *
 * The file lives outside the document root, so a plain web server cannot
 * serve it. Creation is automatic: no shell, no setup step, no manual key
 * generation.
 */
final class Token
{
    public function __construct(private readonly string $file)
    {
    }

    public function ensure(): string
    {
        $existing = $this->read();

        return $existing !== '' ? $existing : $this->create();
    }

    private function read(): string
    {
        if (!is_file($this->file)) {
            return '';
        }

        return trim((string) file_get_contents($this->file));
    }

    private function create(): string
    {
        $dir = dirname($this->file);

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return '';
        }

        $token = bin2hex(random_bytes(32));
        $tmp = $this->file . '.tmp';

        if (@file_put_contents($tmp, $token . "\n") === false) {
            return '';
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);

            return '';
        }

        return $token;
    }
}
