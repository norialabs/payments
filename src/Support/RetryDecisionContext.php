<?php

namespace Noria\Payments\Support;

use Illuminate\Http\Client\Response;

class RetryDecisionContext
{
    public function __construct(
        public readonly int $attempt,
        public readonly int $maxAttempts,
        public readonly string $method,
        public readonly string $url,
        public readonly ?int $status = null,
        public readonly mixed $error = null,
        public readonly ?Response $response = null,
        public readonly mixed $responseBody = null,
    ) {}
}
