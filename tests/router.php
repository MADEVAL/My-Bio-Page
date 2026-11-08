<?php
declare(strict_types=1);

/**
 * Dev router for `php -S`. In production nginx does this job
 * (see deploy/nginx-bio.conf); the deny list below mirrors it.
 */
$root = getenv('BIO_ROOT') ?: dirname(__DIR__) . '/app';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

$denied = ['content', 'src', 'templates', 'cache', 'tests', 'deploy'];
$segment = explode('/', trim($path, '/'))[0] ?? '';

if (in_array($segment, $denied, true) || str_starts_with($path, '/.')) {
    http_response_code(404);
    echo "Not found\n";

    return true;
}

if ($path === '/mcp' || $path === '/mcp.php') {
    $_SERVER['SCRIPT_NAME'] = '/mcp.php';
    require $root . '/mcp.php';

    return true;
}

$static = $root . $path;

if ($path !== '/' && is_file($static) && !str_ends_with($path, '.php')) {
    return false;
}

require $root . '/index.php';

return true;
