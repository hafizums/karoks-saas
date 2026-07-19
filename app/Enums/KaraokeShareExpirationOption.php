<?php

namespace App\Enums;

enum KaraokeShareExpirationOption: string
{
    case Hours24 = '24h';
    case Days7 = '7d';
    case Days30 = '30d';
    case Never = 'never';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $option): string => $option->value,
            self::cases(),
        );
    }

    public function expiresAt(): ?\DateTimeInterface
    {
        return match ($this) {
            self::Hours24 => now()->addHours(24),
            self::Days7 => now()->addDays(7),
            self::Days30 => now()->addDays(30),
            self::Never => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Hours24 => '24 hours',
            self::Days7 => '7 days',
            self::Days30 => '30 days',
            self::Never => 'Never expires',
        };
    }
}
