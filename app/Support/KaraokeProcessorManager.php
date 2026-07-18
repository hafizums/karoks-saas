<?php

namespace App\Support;

use App\Contracts\KaraokeProcessor;
use App\Exceptions\UnsupportedKaroksProcessingDriverException;
use App\Support\Karaoke\Processors\MockKaraokeProcessor;
use App\Support\Karaoke\Processors\RealKaraokeProcessor;

class KaraokeProcessorManager
{
    public function driver(?string $driver = null): KaraokeProcessor
    {
        $driver ??= (string) config('karoks.processing.driver', 'mock');

        return match ($driver) {
            'mock' => $this->resolveMockDriver(),
            'real' => app(RealKaraokeProcessor::class),
            default => throw UnsupportedKaroksProcessingDriverException::forDriver($driver),
        };
    }

    private function resolveMockDriver(): KaraokeProcessor
    {
        if (app()->environment('testing') && app()->bound('karoks.testing.mock_processor')) {
            return app('karoks.testing.mock_processor');
        }

        return app(MockKaraokeProcessor::class);
    }
}
