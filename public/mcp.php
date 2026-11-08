<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__) . '/app';

require $appRoot . '/src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    (new Bio\Response(
        405,
        ['Allow' => 'POST', 'Content-Type' => 'application/json; charset=utf-8'],
        '{"error":"Method Not Allowed"}'
    ))->send();

    return;
}

$authorization = isset($_SERVER['HTTP_AUTHORIZATION']) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : null;

// Read only bounded bodies; the endpoint is authenticated, but this keeps a
// broken or hostile client from making PHP buffer an arbitrary request.
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
    (new Bio\Response(
        413,
        ['Content-Type' => 'application/json; charset=utf-8'],
        '{"error":"Payload Too Large"}'
    ))->send();

    return;
}

(new Bio\App($appRoot))->mcp(
    (string) file_get_contents('php://input'),
    $authorization
)->send();
