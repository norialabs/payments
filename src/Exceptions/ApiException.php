<?php

namespace Noria\Payments\Exceptions;

class ApiException extends PaymentsException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly mixed $responseBody = null,
        mixed $details = null,
    ) {
        parent::__construct($message, 'API_ERROR', $details);
    }
}
