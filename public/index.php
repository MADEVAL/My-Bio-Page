<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__) . '/app';

require $appRoot . '/src/bootstrap.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    (new Bio\Response(
        405,
        ['Allow' => 'GET, HEAD', 'Content-Type' => 'text/plain; charset=utf-8'] + Bio\App::securityHeaders(),
        "Method Not Allowed\n"
    ))->send();

    return;
}

(new Bio\App($appRoot))->page(
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string) $_SERVER['HTTP_IF_NONE_MATCH'] : null,
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD'
)->send();
