<?php

namespace Noria\Payments\Exceptions;

use RuntimeException;

class PaymentsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $codeName = 'PAYMENTS_ERROR',
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }
}
