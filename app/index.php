<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

(new Bio\App(__DIR__))->page(
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string) $_SERVER['HTTP_IF_NONE_MATCH'] : null
)->send();
