<?php

namespace App\Enums;

enum KaraokeProjectStatus: string
{
    case Uploaded = 'uploaded';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    public function isActiveProcessing(): bool
    {
        return in_array($this, [self::Queued, self::Processing], true);
    }
}
