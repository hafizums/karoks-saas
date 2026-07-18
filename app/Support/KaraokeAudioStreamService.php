<?php

namespace App\Support;

use App\Models\KaraokeProject;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KaraokeAudioStreamService
{
    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    public function parseRange(?string $rangeHeader, int $fileSize): ?array
    {
        if ($rangeHeader === null || $rangeHeader === '') {
            return null;
        }

        if (! preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches)) {
            return [-1, -1, $fileSize];
        }

        $start = $matches[1] !== '' ? (int) $matches[1] : null;
        $end = $matches[2] !== '' ? (int) $matches[2] : null;

        if ($start === null && $end === null) {
            return [-1, -1, $fileSize];
        }

        if ($start === null) {
            $suffixLength = $end ?? 0;
            if ($suffixLength <= 0) {
                return [-1, -1, $fileSize];
            }

            $start = max(0, $fileSize - $suffixLength);
            $end = $fileSize - 1;
        } elseif ($end === null) {
            $end = $fileSize - 1;
        }

        if ($start < 0 || $end < $start || $start >= $fileSize) {
            return [-1, -1, $fileSize];
        }

        $end = min($end, $fileSize - 1);

        return [$start, $end, $fileSize];
    }

    public function respond(KaraokeProject $project, ?string $rangeHeader, bool $head = false): Response
    {
        $disk = Storage::disk('local');
        $path = $project->source_path;

        if (! $disk->exists($path)) {
            abort(404);
        }

        $absolutePath = $disk->path($path);
        $fileSize = filesize($absolutePath);

        if ($fileSize === false) {
            abort(404);
        }

        $baseHeaders = [
            'Content-Type' => $project->mime_type,
            'Content-Disposition' => 'inline',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ];

        $parsedRange = $this->parseRange($rangeHeader, $fileSize);

        if ($parsedRange !== null && $parsedRange[0] === -1) {
            return response('', 416, array_merge($baseHeaders, [
                'Content-Range' => "bytes */{$fileSize}",
            ]));
        }

        if ($parsedRange === null) {
            if ($head) {
                return response('', 200, array_merge($baseHeaders, [
                    'Content-Length' => (string) $fileSize,
                ]));
            }

            return $this->stream($absolutePath, 0, $fileSize, 200, array_merge($baseHeaders, [
                'Content-Length' => (string) $fileSize,
            ]));
        }

        [$start, $end, $total] = $parsedRange;
        $length = $end - $start + 1;

        if ($head) {
            return response('', 206, array_merge($baseHeaders, [
                'Content-Length' => (string) $length,
                'Content-Range' => "bytes {$start}-{$end}/{$total}",
            ]));
        }

        return $this->stream($absolutePath, $start, $length, 206, array_merge($baseHeaders, [
            'Content-Length' => (string) $length,
            'Content-Range' => "bytes {$start}-{$end}/{$total}",
        ]));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function stream(string $absolutePath, int $start, int $length, int $status, array $headers): StreamedResponse
    {
        return response()->stream(function () use ($absolutePath, $start, $length): void {
            $handle = fopen($absolutePath, 'rb');

            if ($handle === false) {
                return;
            }

            fseek($handle, $start);

            $remaining = $length;
            $chunkSize = 8192;

            while ($remaining > 0 && ! feof($handle)) {
                $readLength = min($chunkSize, $remaining);
                $buffer = fread($handle, $readLength);

                if ($buffer === false) {
                    break;
                }

                echo $buffer;
                $remaining -= strlen($buffer);
            }

            fclose($handle);
        }, $status, $headers);
    }
}
