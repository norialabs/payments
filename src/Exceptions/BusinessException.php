<?php

namespace Noria\Payments\Exceptions;

class BusinessException extends PaymentsException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly ?string $statusCode = null,
        public readonly mixed $responseBody = null,
    ) {
        parent::__construct($message, 'BUSINESS_ERROR', $responseBody);
    }
}
