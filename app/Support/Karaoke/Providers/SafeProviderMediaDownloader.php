<?php

namespace App\Support\Karaoke\Providers;

use App\Exceptions\KaraokeProviderProcessingException;
use App\Rules\ValidKaraokeAudio;
use Illuminate\Http\Client\Response;
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
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        $tempPath = $tempDirectory.'/'.$filenamePrefix.'-'.Str::uuid().'.part';
        $currentUrl = $url;
        $redirects = 0;
        $response = null;

        while (true) {
            $this->assertSafeUrl($currentUrl);

            $response = Http::withOptions([
                'stream' => true,
                'allow_redirects' => false,
                'connect_timeout' => $connectTimeout,
                'timeout' => $requestTimeout,
            ])->get($currentUrl);

            if ($response->redirect()) {
                @unlink($tempPath);
                $redirects++;

                if ($redirects > $maxRedirects) {
                    throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
                }

                $location = $response->header('Location');

                if (! is_string($location) || $location === '') {
                    throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
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

            $this->assertContentLengthWithinLimit($response, $maxBytes);
            $this->streamResponseToFile($response, $tempPath, $maxBytes);

            break;
        }

        if (! is_file($tempPath)) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        $detectedMime = $this->detectMimeType($tempPath);

        if ($detectedMime === null || ! in_array($detectedMime, self::ALLOWED_AUDIO_MIMES, true)) {
            @unlink($tempPath);

            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        $extension = ValidKaraokeAudio::safeExtensionFromMime($detectedMime);

        if ($extension === null) {
            @unlink($tempPath);

            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        $finalPath = $tempDirectory.'/'.$filenamePrefix.'-'.Str::uuid().'.'.$extension;

        if (! rename($tempPath, $finalPath)) {
            @unlink($tempPath);

            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        return [
            'path' => $finalPath,
            'mime_type' => $detectedMime,
            'extension' => $extension,
        ];
    }

    public function assertSafeUrl(string $url): void
    {
        $parsed = parse_url($url);

        if ($parsed === false) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower((string) ($parsed['host'] ?? ''));
        $port = $parsed['port'] ?? null;

        if ($scheme !== 'https' || $host === '') {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        if ($port !== null && ! in_array((int) $port, [443], true)) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        if (! $this->hostAllowed($host)) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) {
                throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
            }

            return;
        }

        $this->assertResolvablePublicHost($host);
    }

    private function hostAllowed(string $host): bool
    {
        $suffixes = config('karoks.providers.allowed_media_host_suffixes', []);

        if (! is_array($suffixes) || $suffixes === []) {
            return false;
        }

        foreach ($suffixes as $suffix) {
            if (! is_string($suffix) || $suffix === '') {
                continue;
            }

            $normalized = strtolower($suffix);

            if ($host === $normalized || str_ends_with($host, '.'.$normalized)) {
                return true;
            }
        }

        return false;
    }

    private function assertResolvablePublicHost(string $host): void
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (! is_array($records) || $records === []) {
            if (app()->environment('testing') && str_ends_with($host, '.test')) {
                return;
            }

            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        $hasPublic = false;

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && $this->isPublicIp($ip)) {
                $hasPublic = true;

                continue;
            }

            if (is_string($ip) && ! $this->isPublicIp($ip)) {
                throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
            }
        }

        if (! $hasPublic) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }
    }

    private function assertContentLengthWithinLimit(Response $response, int $maxBytes): void
    {
        $contentLength = trim((string) $response->header('Content-Length'));

        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $maxBytes) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }
    }

    private function streamResponseToFile(Response $response, string $tempPath, int $maxBytes): void
    {
        $stream = $response->toPsrResponse()->getBody();
        $handle = fopen($tempPath, 'wb');

        if ($handle === false) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        $written = 0;

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(8192);

                if ($chunk === '') {
                    break;
                }

                $written += strlen($chunk);

                if ($written > $maxBytes) {
                    throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
                }

                $bytes = fwrite($handle, $chunk);

                if ($bytes === false) {
                    throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
                }
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($tempPath);

            if ($exception instanceof KaraokeProviderProcessingException) {
                throw $exception;
            }

            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }

        fclose($handle);

        if ($written <= 0) {
            @unlink($tempPath);

            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
        }
    }

    private function detectMimeType(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        if (! is_string($mime) || $mime === '') {
            return null;
        }

        return strtolower(explode(';', $mime)[0]);
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        if (str_starts_with($location, 'https://') || str_starts_with($location, 'http://')) {
            return $location;
        }

        $base = parse_url($currentUrl);

        if ($base === false) {
            throw app(KaraokeProviderErrorMapper::class)->invalidProviderOutput(false);
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
