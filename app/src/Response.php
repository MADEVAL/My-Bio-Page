<?php
declare(strict_types=1);

namespace Bio;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public function send(): void
    {
        // Never advertise the PHP version in responses.
        @header_remove('X-Powered-By');

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }
}
