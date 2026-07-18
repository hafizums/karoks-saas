<?php

namespace App\Exceptions;

class KaraokeProviderProcessingException extends KaraokeProcessingException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $userMessage,
        public readonly bool $queueRetryable,
        public readonly bool $manualRetryable = true,
    ) {
        parent::__construct($errorCode);
    }
}
