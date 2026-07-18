<?php

namespace App\Enums;

enum KaraokeProcessingStage: string
{
    case Preparing = 'preparing';
    case Separating = 'separating';
    case Transcribing = 'transcribing';
    case Assembling = 'assembling';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Preparing => 'Preparing',
            self::Separating => 'Separating vocals',
            self::Transcribing => 'Transcribing lyrics',
            self::Assembling => 'Assembling project',
            self::Completed => 'Completed',
        };
    }

    public function progress(): int
    {
        return match ($this) {
            self::Preparing => 10,
            self::Separating => 30,
            self::Transcribing => 60,
            self::Assembling => 85,
            self::Completed => 100,
        };
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Preparing,
            self::Separating,
            self::Transcribing,
            self::Assembling,
            self::Completed,
        ];
    }
}
