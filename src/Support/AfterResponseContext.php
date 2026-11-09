<?php

namespace Noria\Payments\Support;

use Illuminate\Http\Client\Response;

class AfterResponseContext extends BeforeRequestContext
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
        public Response $response,
        public mixed $responseBody,
    ) {
        parent::__construct($url, $path, $method, $headers, $body, $attempt);
    }
}
