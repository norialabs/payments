<?php

namespace Noria\Payments\Exceptions;

class NetworkException extends PaymentsException
{
    public function __construct(string $message, mixed $details = null)
    {
        parent::__construct($message, 'NETWORK_ERROR', $details);
    }
}
