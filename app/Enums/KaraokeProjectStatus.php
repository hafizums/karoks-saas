<?php

namespace App\Enums;

enum KaraokeProjectStatus: string
{
    case Uploaded = 'uploaded';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
        };
    }
}
