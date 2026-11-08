<?php
declare(strict_types=1);

namespace Bio;

/**
 * JSON-RPC level failure (protocol/transport), not a tool failure.
 */
final class McpError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $rpcCode)
    {
        parent::__construct($message);
    }
}
