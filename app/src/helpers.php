<?php
declare(strict_types=1);

/**
 * Escape for HTML text and attribute contexts.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Pick a localised value out of ["en" => ..., "ru" => ...].
 */
function tr(array $field, string $lang): string
{
    return (string) ($field[$lang] ?? '');
}

/**
 * Turn a content-relative asset path into a root-absolute URL.
 */
function asset(string $path): string
{
    return '/' . ltrim($path, '/');
}
