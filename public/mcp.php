<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__) . '/app';

require $appRoot . '/src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    (new Bio\Response(
        405,
        ['Allow' => 'POST', 'Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'] + Bio\App::securityHeaders(),
        '{"error":"Method Not Allowed"}'
    ))->send();

    return;
}

$authorization = isset($_SERVER['HTTP_AUTHORIZATION']) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : null;
$limit = 1048576;
$tooLarge = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit;
$body = '';

if (!$tooLarge) {
    $app = new Bio\App($appRoot);
    $probe = $app->mcp('', $authorization);

    if ($probe->status === 401) {
        $probe->send();

        return;
    }

    $stream = @fopen('php://input', 'rb');
    $body = is_resource($stream) ? (string) stream_get_contents($stream, $limit + 1) : '';

    if (is_resource($stream)) {
        fclose($stream);
    }

    $tooLarge = strlen($body) > $limit;
}

if ($tooLarge) {
    (new Bio\Response(
        413,
        ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'] + Bio\App::securityHeaders(),
        '{"error":"Payload Too Large"}'
    ))->send();

    return;
}

$app->mcp($body, $authorization)->send();
