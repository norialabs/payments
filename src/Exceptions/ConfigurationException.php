<?php

namespace Noria\Payments\Exceptions;

class ConfigurationException extends PaymentsException
{
    public function __construct(string $message, mixed $details = null)
    {
        parent::__construct($message, 'CONFIGURATION_ERROR', $details);
    }
}
