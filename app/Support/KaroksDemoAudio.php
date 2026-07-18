<?php

namespace App\Support;

class KaroksDemoAudio
{
    public const DEMO_DURATION_SECONDS = 27.0;

    public const DEMO_SAMPLE_RATE = 22050;

    public static function fixturePath(): string
    {
        return database_path('fixtures/karoks-demo-audio.wav');
    }

    public static function ensureFixtureExists(): bool
    {
        $path = self::fixturePath();

        if (is_file($path) && filesize($path) > 1000) {
            return true;
        }

        return file_put_contents($path, self::generateSilentWav(self::DEMO_SAMPLE_RATE, self::DEMO_DURATION_SECONDS)) !== false;
    }

    public static function generateSilentWav(int $sampleRate, float $durationSeconds): string
    {
        $numSamples = max(1, (int) round($sampleRate * $durationSeconds));
        $bytesPerSample = 2;
        $dataSize = $numSamples * $bytesPerSample;
        $byteRate = $sampleRate * $bytesPerSample;
        $blockAlign = $bytesPerSample;

        $header = pack(
            'a4Va4a4VvvVVvva4V',
            'RIFF',
            36 + $dataSize,
            'WAVE',
            'fmt ',
            16,
            1,
            1,
            $sampleRate,
            $byteRate,
            $blockAlign,
            16,
            'data',
            $dataSize,
        );

        return $header.str_repeat("\0", $dataSize);
    }
}
