<?php

namespace App\Support\Karaoke\Embed;

use Symfony\Component\HttpFoundation\Response;

class KaraokeEmbedSecurityHeaders
{
    /**
     * @param  list<string>  $allowedOrigins
     */
    public function apply(Response $response, array $allowedOrigins): Response
    {
        $frameAncestors = implode(' ', array_map(
            fn (string $origin): string => $this->escapeCspOrigin($origin),
            $allowedOrigins,
        ));

        $response->headers->set('Content-Security-Policy', 'frame-ancestors '.$frameAncestors);
        $response->headers->remove('X-Frame-Options');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), bluetooth=(), midi=(), magnetometer=(), gyroscope=(), accelerometer=()',
        );

        return $response;
    }

    private function escapeCspOrigin(string $origin): string
    {
        if ($this->containsControlCharacters($origin)) {
            throw new \InvalidArgumentException('Invalid CSP origin.');
        }

        return $origin;
    }

    private function containsControlCharacters(string $value): bool
    {
        return (bool) preg_match('/[\x00-\x1F\x7F]/', $value);
    }
}
