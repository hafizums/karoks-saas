<?php

namespace App\Exceptions;

use RuntimeException;

class KaraokeProcessingDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Karaoke processing is temporarily unavailable.');
    }
}
