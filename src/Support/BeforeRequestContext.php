<?php

namespace Noria\Payments\Support;

class BeforeRequestContext
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $url,
        public string $path,
        public string $method,
        public array $headers,
        public mixed $body,
        public int $attempt,
    ) {}
}
