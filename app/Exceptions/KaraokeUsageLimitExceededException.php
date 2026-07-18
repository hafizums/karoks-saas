<?php

namespace App\Exceptions;

use RuntimeException;

class KaraokeUsageLimitExceededException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Your monthly processing allowance has been reached.');
    }
}
