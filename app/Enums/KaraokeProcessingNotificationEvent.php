<?php

namespace App\Enums;

enum KaraokeProcessingNotificationEvent: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Stalled = 'processing_stalled';

    public function safeErrorCode(): ?string
    {
        return match ($this) {
            self::Completed, self::Cancelled => null,
            self::Failed => 'processing_failed',
            self::Stalled => 'processing_stalled',
        };
    }
}
