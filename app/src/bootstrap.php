<?php
declare(strict_types=1);

/**
 * Minimal autoloader + shared helpers. No composer, no vendor directory.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Bio\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/helpers.php';
