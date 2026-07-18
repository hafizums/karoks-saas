<?php

namespace App\Support\Karaoke\Processing;

class KaraokeProcessingDriverResolver
{
    public function driverName(): string
    {
        return strtolower(trim((string) config('karoks.processing.driver', 'mock')));
    }

    public function isMock(): bool
    {
        return $this->driverName() === 'mock';
    }

    public function isReal(): bool
    {
        return $this->driverName() === 'real';
    }

    public function realConfigured(): bool
    {
        if (! $this->isReal()) {
            return true;
        }

        return $this->realProviderCredentialsConfigured();
    }

    public function realProviderCredentialsConfigured(): bool
    {
        $wavespeedKey = trim((string) config('karoks.providers.wavespeed.api_key', ''));
        $elevenLabsKey = trim((string) config('karoks.providers.elevenlabs.api_key', ''));

        if ($wavespeedKey === '' || $elevenLabsKey === '') {
            return false;
        }

        $pollInterval = (int) config('karoks.providers.poll_interval_seconds', 0);
        $pollTimeout = (int) config('karoks.providers.poll_timeout_seconds', 0);
        $maxDuration = (int) config('karoks.processing.max_audio_duration_seconds', 0);

        return $pollInterval > 0 && $pollTimeout > 0 && $maxDuration > 0;
    }

    public function userFacingModeLabel(): string
    {
        if ($this->isMock()) {
            return 'simulated';
        }

        if ($this->realConfigured()) {
            return 'real';
        }

        return 'unavailable';
    }

    public function requiresProviderConsent(): bool
    {
        return $this->isReal() && $this->realConfigured();
    }

    public function labelForDriver(string $driver): string
    {
        return strtolower(trim($driver)) === 'real' ? 'real' : 'simulated';
    }
}
