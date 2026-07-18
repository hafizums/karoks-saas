<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ValidKaraokeAudio implements ValidationRule
{
    public const ALLOWED_EXTENSIONS = ['mp3', 'wav', 'm4a', 'flac'];

    private const ALLOWED_MIMES = [
        'audio/mpeg',
        'audio/mp3',
        'audio/x-mpeg-3',
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'audio/mp4',
        'audio/x-m4a',
        'audio/m4a',
        'audio/flac',
        'audio/x-flac',
    ];

    private const EXTENSION_BY_MIME = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/x-mpeg-3' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/m4a' => 'm4a',
        'audio/flac' => 'flac',
        'audio/x-flac' => 'flac',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Please choose an audio file.');

            return;
        }

        if (! $value->isValid()) {
            $fail('We could not read this audio file. It may be corrupt or unreadable.');

            return;
        }

        if ($value->getSize() === 0) {
            $fail('This file is empty. Choose a valid audio file.');

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $fail('Use an MP3, WAV, M4A, or FLAC file.');

            return;
        }

        $detectedMime = strtolower((string) $value->getMimeType());

        if ($detectedMime === '' || ! in_array($detectedMime, self::ALLOWED_MIMES, true)) {
            $fail('This file type does not look like supported audio.');

            return;
        }

        $expectedExtension = self::safeExtensionFromMime($detectedMime);

        if ($expectedExtension === null || $extension !== $expectedExtension) {
            $fail('This file type does not look like supported audio.');
        }
    }

    public static function safeExtensionFromMime(string $mime): ?string
    {
        return self::EXTENSION_BY_MIME[strtolower($mime)] ?? null;
    }
}
