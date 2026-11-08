<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    (new Bio\Response(
        405,
        ['Allow' => 'POST', 'Content-Type' => 'application/json; charset=utf-8'],
        '{"error":"Method Not Allowed"}'
    ))->send();

    return;
}

(new Bio\App(__DIR__))->mcp(
    (string) file_get_contents('php://input'),
    isset($_SERVER['HTTP_AUTHORIZATION']) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : null
)->send();
