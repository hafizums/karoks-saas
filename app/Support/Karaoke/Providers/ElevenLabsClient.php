<?php

namespace App\Support\Karaoke\Providers;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ElevenLabsClient
{
    private const STT_URL = 'https://api.elevenlabs.io/v1/speech-to-text';

    private const MAX_RESPONSE_BYTES = 1048576;

    public function __construct(
        private readonly KaraokeProviderErrorMapper $errors,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function transcribeVocalFile(string $absolutePath, string $filename, string $mimeType): array
    {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw $this->errors->invalidAudio();
        }

        try {
            $response = $this->request('elevenlabs', 'transcribe', function () use ($handle, $filename, $mimeType): Response {
                return Http::withHeaders([
                    'xi-api-key' => $this->apiKey(),
                ])
                    ->connectTimeout($this->connectTimeout())
                    ->timeout($this->requestTimeout())
                    ->attach('file', $handle, $filename, ['Content-Type' => $mimeType])
                    ->post(self::STT_URL, [
                        'model_id' => 'scribe_v2',
                        'timestamps_granularity' => 'word',
                        'tag_audio_events' => 'false',
                        'diarize' => 'false',
                    ]);
            });
        } finally {
            fclose($handle);
        }

        $this->guardResponse($response);

        return $this->decodeBody($response);
    }

    private function guardResponse(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw $this->errors->exceptionFromMapping(
            $this->errors->mapHttpFailure('elevenlabs', $response->status(), 'transcribe'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(Response $response): array
    {
        $raw = $response->body();

        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            throw $this->errors->invalidProviderOutput();
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw $this->errors->invalidProviderOutput();
        }

        return $decoded;
    }

    /**
     * @param  Closure(): Response  $callback
     */
    private function request(string $provider, string $step, Closure $callback): Response
    {
        try {
            return $callback();
        } catch (ConnectionException $exception) {
            throw $this->errors->mapTransportFailure($provider, $step, $exception);
        }
    }

    private function apiKey(): string
    {
        $key = trim((string) config('karoks.providers.elevenlabs.api_key', ''));

        if ($key === '') {
            throw $this->errors->notConfigured();
        }

        return $key;
    }

    private function connectTimeout(): int
    {
        return max(1, (int) config('karoks.providers.connect_timeout_seconds', 10));
    }

    private function requestTimeout(): int
    {
        return max(1, (int) config('karoks.providers.request_timeout_seconds', 120));
    }
}
