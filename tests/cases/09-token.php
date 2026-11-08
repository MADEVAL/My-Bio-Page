<?php
declare(strict_types=1);

use Bio\App;
use Bio\Token;

Harness::group('Token');

Harness::test('returns an existing token unchanged', function (): void {
    $root = makeWorkspace();
    $file = $root . '/token';

    try {
        file_put_contents($file, "known-token\n");
        assertSame('known-token', (new Token($file))->ensure());
        assertSame("known-token\n", (string) file_get_contents($file));
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('generates a 64 character token and stores it', function (): void {
    $root = makeWorkspace();
    $file = $root . '/tokens/bio.token';

    try {
        $token = (new Token($file))->ensure();

        assertMatches('~^[0-9a-f]{64}$~', $token);
        assertTrue(is_file($file));
        assertSame($token, trim((string) file_get_contents($file)));
        assertSame($token, (new Token($file))->ensure());
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('replaces an empty token file', function (): void {
    $root = makeWorkspace();
    $file = $root . '/token';

    try {
        file_put_contents($file, "  \n");
        assertMatches('~^[0-9a-f]{64}$~', (new Token($file))->ensure());
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('gives up when the directory cannot be created', function (): void {
    $root = makeWorkspace();
    $blocker = $root . '/blocked';

    try {
        file_put_contents($blocker, 'x');
        assertSame('', (new Token($blocker . '/deep/token'))->ensure());
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('gives up when the temporary file cannot be written', function (): void {
    $root = makeWorkspace();
    $file = $root . '/token';

    try {
        mkdir($file . '.tmp', 0775, true);
        assertSame('', (new Token($file))->ensure());
        assertFalse(is_file($file));
    } finally {
        removeWorkspace($root);
    }
});

Harness::test('gives up when the token cannot be moved into place', function (): void {
    $root = makeWorkspace();
    $file = $root . '/token';

    try {
        mkdir($file, 0775, true);
        assertSame('', (new Token($file))->ensure());
    } finally {
        removeWorkspace($root);
    }
});

Harness::group('Token: wiring');

Harness::test('App provisions the token on first use', function (): void {
    $root = makeWorkspace();
    $tokenFile = outsideTokenPath($root);

    try {
        @unlink($tokenFile);
        putenv('MCP_TOKEN');
        putenv('BIO_TOKEN_FILE');

        $app = new App($root);

        assertTrue(is_file($tokenFile));
        $token = trim((string) file_get_contents($tokenFile));
        assertMatches('~^[0-9a-f]{64}$~', $token);
        assertSame(200, $app->mcp('', 'Bearer ' . $token)->status);

        $second = new App($root);
        assertSame(200, $second->mcp('', 'Bearer ' . $token)->status);
        assertSame(401, $second->mcp('', 'Bearer anything')->status);
    } finally {
        @unlink($tokenFile);
        removeWorkspace($root);
    }
});

Harness::test('App honours BIO_TOKEN_FILE', function (): void {
    $root = makeWorkspace();
    $file = $root . '/custom/token';

    try {
        putenv('MCP_TOKEN');
        putenv('BIO_TOKEN_FILE=' . $file);

        $app = new App($root);

        assertTrue(is_file($file));
        $token = trim((string) file_get_contents($file));
        assertSame(200, $app->mcp('', 'Bearer ' . $token)->status);
    } finally {
        putenv('BIO_TOKEN_FILE');
        removeWorkspace($root);
    }
});

Harness::test('App stays locked when the token cannot be provisioned', function (): void {
    $root = makeWorkspace();
    $blocker = $root . '/blocked';

    try {
        file_put_contents($blocker, 'x');
        putenv('MCP_TOKEN');
        putenv('BIO_TOKEN_FILE=' . $blocker . '/token');

        $app = new App($root);

        assertSame(401, $app->mcp('', 'Bearer anything')->status);
    } finally {
        putenv('BIO_TOKEN_FILE');
        removeWorkspace($root);
    }
});
