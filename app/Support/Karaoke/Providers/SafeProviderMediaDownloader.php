<?php

namespace App\Support\Karaoke\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SafeProviderMediaDownloader
{
    private const ALLOWED_AUDIO_MIMES = [
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'audio/mp4',
        'audio/x-m4a',
        'audio/m4a',
        'audio/flac',
        'audio/x-flac',
        'application/octet-stream',
    ];

    /**
     * @return array{path: string, mime_type: string, extension: string}
     */
    public function downloadToTemp(string $url, string $tempDirectory, string $filenamePrefix): array
    {
        $this->assertSafeUrl($url);

        $maxBytes = max(1, (int) config('karoks.providers.max_download_bytes', 52428800));
        $maxRedirects = max(0, (int) config('karoks.providers.max_download_redirects', 3));
        $connectTimeout = max(1, (int) config('karoks.providers.connect_timeout_seconds', 10));
        $requestTimeout = max(1, (int) config('karoks.providers.request_timeout_seconds', 120));

        if (! is_dir($tempDirectory) && ! mkdir($tempDirectory, 0700, true) && ! is_dir($tempDirectory)) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
        }

        $tempPath = $tempDirectory.'/'.$filenamePrefix.'-'.Str::uuid().'.part';
        $currentUrl = $url;
        $redirects = 0;

        while (true) {
            $this->assertSafeUrl($currentUrl);

            $response = Http::withOptions([
                'sink' => $tempPath,
                'allow_redirects' => false,
                'connect_timeout' => $connectTimeout,
                'timeout' => $requestTimeout,
            ])->get($currentUrl);

            if ($response->redirect()) {
                @unlink($tempPath);
                $redirects++;

                if ($redirects > $maxRedirects) {
                    throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
                }

                $location = $response->header('Location');

                if (! is_string($location) || $location === '') {
                    throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
                }

                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);

                continue;
            }

            if (! $response->successful()) {
                @unlink($tempPath);

                throw app(KaraokeProviderErrorMapper::class)->exceptionFromMapping(
                    app(KaraokeProviderErrorMapper::class)->mapHttpFailure('wavespeed', $response->status(), 'download'),
                );
            }

            break;
        }

        if (! is_file($tempPath)) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
        }

        $size = filesize($tempPath);

        if ($size === false || $size <= 0 || $size > $maxBytes) {
            @unlink($tempPath);

            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
        }

        $mimeType = strtolower(trim((string) $response->header('Content-Type')));
        $mimeType = explode(';', $mimeType)[0];

        if (! in_array($mimeType, self::ALLOWED_AUDIO_MIMES, true)) {
            @unlink($tempPath);

            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
        }

        $extension = match ($mimeType) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/mp4', 'audio/x-m4a', 'audio/m4a' => 'm4a',
            'audio/flac', 'audio/x-flac' => 'flac',
            default => 'bin',
        };

        if ($extension === 'bin') {
            @unlink($tempPath);

            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
        }

        $finalPath = $tempDirectory.'/'.$filenamePrefix.'-'.Str::uuid().'.'.$extension;

        if (! rename($tempPath, $finalPath)) {
            @unlink($tempPath);

            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
        }

        return [
            'path' => $finalPath,
            'mime_type' => $mimeType === 'application/octet-stream' ? 'audio/mpeg' : $mimeType,
            'extension' => $extension,
        ];
    }

    public function assertSafeUrl(string $url): void
    {
        $parsed = parse_url($url);

        if ($parsed === false) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower((string) ($parsed['host'] ?? ''));
        $port = $parsed['port'] ?? null;

        if ($scheme !== 'https' || $host === '') {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
        }

        if ($port !== null && ! in_array((int) $port, [443], true)) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
        }

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) {
                throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
            }

            return;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($ip) && ! $this->isPublicIp($ip)) {
                    throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
                }
            }
        }
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        if (str_starts_with($location, 'https://')) {
            return $location;
        }

        $base = parse_url($currentUrl);

        if ($base === false) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput();
        }

        if (str_starts_with($location, '/')) {
            return 'https://'.($base['host'] ?? '').$location;
        }

        $path = $base['path'] ?? '/';
        $dir = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '/';

        return 'https://'.($base['host'] ?? '').$dir.$location;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
