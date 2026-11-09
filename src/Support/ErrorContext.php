<?php

namespace Noria\Payments\Support;

use Illuminate\Http\Client\Response;

class ErrorContext extends BeforeRequestContext
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $url,
        string $path,
        string $method,
        array $headers,
        mixed $body,
        int $attempt,
        public mixed $error,
        public ?Response $response = null,
        public mixed $responseBody = null,
    ) {
        parent::__construct($url, $path, $method, $headers, $body, $attempt);
    }
}
