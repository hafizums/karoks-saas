<?php

namespace App\Exceptions;

use RuntimeException;

class UnsupportedKaroksProcessingDriverException extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return new self("Unsupported Karoks processing driver [{$driver}]. Only the local mock driver is available in this phase.");
    }
}
