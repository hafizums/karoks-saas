<?php

namespace App\Support\Karaoke\Embed;

use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;

class KaraokeEmbedOriginParser
{
    public const MAX_ORIGINS = 10;

    public const MAX_ORIGIN_LENGTH = 255;

    public const MAX_SERIALIZED_SIZE = 2048;

    /**
     * @param  list<string>  $inputs
     * @return list<string>
     */
    public function parseMany(array $inputs): array
    {
        $normalized = [];

        foreach ($inputs as $input) {
            if (! is_string($input)) {
                continue;
            }

            foreach (preg_split('/\R/u', $input) ?: [] as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $origin = $this->parseOne($line);

                if ($origin === null) {
                    throw ValidationException::withMessages([
                        'embed_allowed_origins' => 'Each allowed origin must be a valid HTTPS origin such as https://example.com.',
                    ]);
                }

                $normalized[$origin] = $origin;
            }
        }

        $origins = array_values($normalized);

        if ($origins === []) {
            throw ValidationException::withMessages([
                'embed_allowed_origins' => 'At least one allowed origin is required to enable embedding.',
            ]);
        }

        if (count($origins) > self::MAX_ORIGINS) {
            throw ValidationException::withMessages([
                'embed_allowed_origins' => 'You may allow at most '.self::MAX_ORIGINS.' origins.',
            ]);
        }

        $serialized = json_encode($origins);

        if ($serialized === false || strlen($serialized) > self::MAX_SERIALIZED_SIZE) {
            throw ValidationException::withMessages([
                'embed_allowed_origins' => 'The allowed origin list is too large.',
            ]);
        }

        return $origins;
    }

    public function parseOne(string $input): ?string
    {
        $input = trim($input);

        if ($input === '' || strlen($input) > self::MAX_ORIGIN_LENGTH) {
            return null;
        }

        if ($this->containsControlCharacters($input) || str_contains($input, '*')) {
            return null;
        }

        if (str_starts_with($input, '//')) {
            return null;
        }

        $parts = parse_url($input);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if ($path !== '' && $path !== '/') {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (in_array($scheme, ['javascript', 'data', 'file'], true)) {
            return null;
        }

        if ($host === '' || str_contains($host, '*')) {
            return null;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($scheme === 'https') {
            if ($port === 443) {
                $port = null;
            }

            if ($this->isBlockedProductionHost($host)) {
                return null;
            }

            return $this->formatOrigin('https', $host, $port);
        }

        if ($scheme === 'http' && $this->allowsLocalHttpOrigin($host, $port)) {
            if ($port === 80) {
                $port = null;
            }

            return $this->formatOrigin('http', $host, $port);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function normalizeStoredOrigins(?array $origins): array
    {
        if ($origins === null || $origins === []) {
            return [];
        }

        $normalized = [];

        foreach ($origins as $origin) {
            if (! is_string($origin)) {
                continue;
            }

            $parsed = $this->parseOne($origin);

            if ($parsed !== null) {
                $normalized[$parsed] = $parsed;
            }
        }

        return array_values($normalized);
    }

    private function allowsLocalHttpOrigin(string $host, ?int $port): bool
    {
        if (! App::environment(['local', 'testing'])) {
            return false;
        }

        return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    private function isBlockedProductionHost(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isPrivateOrReservedIpv4($host);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->isPrivateOrReservedIpv6($host);
        }

        return false;
    }

    private function isPrivateOrReservedIpv4(string $host): bool
    {
        $long = ip2long($host);

        if ($long === false) {
            return true;
        }

        $ranges = [
            ['0.0.0.0', '0.255.255.255'],
            ['10.0.0.0', '10.255.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['224.0.0.0', '239.255.255.255'],
            ['240.0.0.0', '255.255.255.255'],
        ];

        foreach ($ranges as [$start, $end]) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateOrReservedIpv6(string $host): bool
    {
        $packed = inet_pton($host);

        if ($packed === false) {
            return true;
        }

        if ($packed === inet_pton('::1')) {
            return true;
        }

        $firstByte = ord($packed[0]);
        $secondByte = ord($packed[1]);

        if ($firstByte === 0xFE && ($secondByte & 0xC0) === 0x80) {
            return true;
        }

        if (($firstByte & 0xFE) === 0xFC) {
            return true;
        }

        if ($firstByte === 0xFF) {
            return true;
        }

        return false;
    }

    private function formatOrigin(string $scheme, string $host, ?int $port): ?string
    {
        $origin = $scheme.'://'.$host.($port !== null ? ':'.$port : '');

        if (strlen($origin) > self::MAX_ORIGIN_LENGTH) {
            return null;
        }

        return $origin;
    }

    private function containsControlCharacters(string $value): bool
    {
        return (bool) preg_match('/[\x00-\x1F\x7F]/', $value);
    }
}
