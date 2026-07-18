<?php

namespace App\Exceptions;

use RuntimeException;

class KaraokeUsageConfigurationException extends RuntimeException
{
    public function __construct(string $message = 'Karaoke usage configuration is invalid.')
    {
        parent::__construct($message);
    }
}
