<?php

namespace App\Support;

class KaraokeThemeParser
{
    public const BACKGROUND_PRESETS = ['noir-gold', 'midnight-blue', 'neon-berry'];

    public const LYRIC_SIZES = ['small', 'medium', 'large'];

    /**
     * @return array{backgroundPreset: string, lyricSize: string, baseColor: string, highlightColor: string}
     */
    public static function parse(mixed $input): array
    {
        $defaults = self::defaults();

        if (! is_array($input)) {
            return $defaults;
        }

        return [
            'backgroundPreset' => self::parseBackgroundPreset($input['backgroundPreset'] ?? null) ?? $defaults['backgroundPreset'],
            'lyricSize' => self::parseLyricSize($input['lyricSize'] ?? null) ?? $defaults['lyricSize'],
            'baseColor' => self::sanitizeHexColor($input['baseColor'] ?? null) ?? $defaults['baseColor'],
            'highlightColor' => self::sanitizeHexColor($input['highlightColor'] ?? null) ?? $defaults['highlightColor'],
        ];
    }

    /**
     * @return array{backgroundPreset: string, lyricSize: string, baseColor: string, highlightColor: string}
     */
    public static function defaults(): array
    {
        return [
            'backgroundPreset' => 'noir-gold',
            'lyricSize' => 'medium',
            'baseColor' => '#f4f0e6',
            'highlightColor' => '#f0c14b',
        ];
    }

    /**
     * @param  array{backgroundPreset: string, lyricSize: string, baseColor: string, highlightColor: string}  $theme
     * @return array<string, string>
     */
    public static function cssVariables(array $theme): array
    {
        return [
            '--lyric-base' => $theme['baseColor'],
            '--lyric-highlight' => $theme['highlightColor'],
            '--lyric-highlight-glow' => $theme['highlightColor'].'47',
        ];
    }

    public static function sanitizeHexColor(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $trimmed)) {
            return null;
        }

        return strtolower($trimmed);
    }

    private static function parseBackgroundPreset(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::BACKGROUND_PRESETS, true) ? $value : null;
    }

    private static function parseLyricSize(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::LYRIC_SIZES, true) ? $value : null;
    }
}
