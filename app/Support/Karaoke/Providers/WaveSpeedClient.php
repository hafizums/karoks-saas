<?php

namespace App\Support\Karaoke\Providers;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WaveSpeedClient
{
    private const UPLOAD_URL = 'https://api.wavespeed.ai/api/v3/media/upload/binary';

    private const ISOLATOR_URL = 'https://api.wavespeed.ai/api/v3/wavespeed-ai/audio-vocal-isolator';

    private const MAX_RESPONSE_BYTES = 65536;

    /**
     * @var list<string>
     */
    private const TERMINAL_FAILURE_STATUSES = ['failed', 'cancelled', 'timeout'];

    /**
     * @var list<string>
     */
    private const ACTIVE_STATUSES = ['created', 'processing'];

    public function __construct(
        private readonly KaraokeProviderErrorMapper $errors,
    ) {}

    public function uploadSourceFile(string $absolutePath, string $filename, string $mimeType): string
    {
        $apiKey = $this->apiKey();
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw $this->errors->invalidAudio();
        }

        try {
            $response = $this->request('wavespeed', 'upload', function () use ($apiKey, $handle, $filename, $mimeType): Response {
                return Http::withToken($apiKey)
                    ->connectTimeout($this->connectTimeout())
                    ->timeout($this->requestTimeout())
                    ->attach('file', $handle, $filename, ['Content-Type' => $mimeType])
                    ->post(self::UPLOAD_URL);
            });
        } finally {
            fclose($handle);
        }

        $this->guardResponse($response, 'wavespeed', 'upload');

        $body = $this->decodeBody($response);
        $url = $this->extractUploadUrl($body);

        if ($url === null) {
            throw $this->errors->invalidProviderOutput(false);
        }

        return $url;
    }

    public function submitVocalIsolation(string $audioUrl): string
    {
        $response = $this->request('wavespeed', 'isolator_submit', function () use ($audioUrl): Response {
            return Http::withToken($this->apiKey())
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->requestTimeout())
                ->acceptJson()
                ->post(self::ISOLATOR_URL, [
                    'audio' => $audioUrl,
                ]);
        });

        $this->guardResponse($response, 'wavespeed', 'isolator_submit');

        $body = $this->decodeBody($response);
        $data = $this->unwrapData($body);
        $predictionId = is_string($data['id'] ?? null) ? $data['id'] : null;

        if ($predictionId === null || $predictionId === '') {
            throw $this->errors->invalidProviderOutput(true);
        }

        return $predictionId;
    }

    /**
     * @return array{status: string, vocal_url: string|null, instrumental_url: string|null}
     */
    public function fetchPredictionStatus(string $predictionId): array
    {
        $response = $this->request('wavespeed', 'poll', function () use ($predictionId): Response {
            return Http::withToken($this->apiKey())
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->requestTimeout())
                ->acceptJson()
                ->get($this->resultUrl($predictionId));
        });

        $this->guardResponse($response, 'wavespeed', 'poll');

        $body = $this->decodeBody($response);
        $data = $this->unwrapData($body);
        $status = is_string($data['status'] ?? null) ? strtolower($data['status']) : '';

        if (! in_array($status, array_merge(self::ACTIVE_STATUSES, ['completed'], self::TERMINAL_FAILURE_STATUSES), true)) {
            throw $this->errors->invalidProviderOutput(true);
        }

        if ($status !== 'completed') {
            return [
                'status' => $status,
                'vocal_url' => null,
                'instrumental_url' => null,
            ];
        }

        $outputs = $this->parseOutputs($data['outputs'] ?? null);

        return [
            'status' => 'completed',
            'vocal_url' => $outputs['vocal_url'],
            'instrumental_url' => $outputs['instrumental_url'],
        ];
    }

    /**
     * @param  callable(): void  $onPoll
     * @return array{vocal_url: string, instrumental_url: string}
     */
    public function pollUntilCompleted(string $predictionId, callable $onPoll): array
    {
        $interval = max(1, (int) config('karoks.providers.poll_interval_seconds', 2));
        $timeout = max($interval, (int) config('karoks.providers.poll_timeout_seconds', 600));
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $onPoll();

            $status = $this->fetchPredictionStatus($predictionId);

            if ($status['status'] === 'completed') {
                if ($status['vocal_url'] === null || $status['instrumental_url'] === null) {
                    throw $this->errors->invalidProviderOutput(true);
                }

                return [
                    'vocal_url' => $status['vocal_url'],
                    'instrumental_url' => $status['instrumental_url'],
                ];
            }

            if (in_array($status['status'], self::TERMINAL_FAILURE_STATUSES, true)) {
                throw $this->errors->providerTerminalFailure($status['status']);
            }

            sleep($interval);
        }

        throw $this->errors->providerPollingDeadlineExceeded();
    }

    /**
     * @return array{vocal_url: string, instrumental_url: string}
     */
    private function parseOutputs(mixed $outputs): array
    {
        if (! is_array($outputs) || count($outputs) !== 2) {
            throw $this->errors->invalidProviderOutput(true);
        }

        $vocalUrl = $outputs[0] ?? null;
        $instrumentalUrl = $outputs[1] ?? null;

        if (! $this->isHttpsUrl($vocalUrl) || ! $this->isHttpsUrl($instrumentalUrl)) {
            throw $this->errors->invalidProviderOutput(true);
        }

        return [
            'vocal_url' => $vocalUrl,
            'instrumental_url' => $instrumentalUrl,
        ];
    }

    private function extractUploadUrl(array $body): ?string
    {
        $data = $this->unwrapData($body);
        $candidate = $data['download_url'] ?? $data['url'] ?? null;

        return $this->isHttpsUrl($candidate) ? $candidate : null;
    }

    private function isHttpsUrl(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        $parsed = parse_url($value);

        return is_array($parsed) && ($parsed['scheme'] ?? '') === 'https';
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrapData(array $body): array
    {
        if (isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(Response $response): array
    {
        $raw = $response->body();

        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            throw $this->errors->invalidProviderOutput(false);
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw $this->errors->invalidProviderOutput(false);
        }

        return $decoded;
    }

    private function guardResponse(Response $response, string $provider, string $step): void
    {
        if ($response->successful()) {
            return;
        }

        throw $this->errors->exceptionFromMapping(
            $this->errors->mapHttpFailure($provider, $response->status(), $step),
        );
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

    private function resultUrl(string $predictionId): string
    {
        return 'https://api.wavespeed.ai/api/v3/predictions/'.rawurlencode($predictionId).'/result';
    }

    private function apiKey(): string
    {
        $key = trim((string) config('karoks.providers.wavespeed.api_key', ''));

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
