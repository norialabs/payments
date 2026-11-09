<?php

namespace Noria\Payments\Exceptions;

class ValidationException extends PaymentsException
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        mixed $details = null,
    ) {
        parent::__construct($message, 'VALIDATION_ERROR', $details);
    }
}
