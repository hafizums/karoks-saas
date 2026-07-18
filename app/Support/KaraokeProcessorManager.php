<?php

namespace App\Support;

use App\Contracts\KaraokeProcessor;
use App\Exceptions\UnsupportedKaroksProcessingDriverException;
use App\Support\Karaoke\Processors\MockKaraokeProcessor;

class KaraokeProcessorManager
{
    public function driver(?string $driver = null): KaraokeProcessor
    {
        $driver ??= (string) config('karoks.processing.driver', 'mock');

        return match ($driver) {
            'mock' => app(MockKaraokeProcessor::class),
            default => throw UnsupportedKaroksProcessingDriverException::forDriver($driver),
        };
    }
}
