<?php

namespace App\Support\Karaoke\Processing;

use App\Rules\ValidKaraokeAudio;
use RuntimeException;

class KaraokeAudioDurationInspector
{
    /**
     * @return array{duration_seconds: int, readable: true}|array{duration_seconds: null, readable: false, reason: string}
     */
    public function inspectFile(string $absolutePath, string $mimeType): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return ['duration_seconds' => null, 'readable' => false, 'reason' => 'unreadable'];
        }

        $size = filesize($absolutePath);

        if ($size === false || $size <= 0) {
            return ['duration_seconds' => null, 'readable' => false, 'reason' => 'empty'];
        }

        $extension = ValidKaraokeAudio::safeExtensionFromMime(strtolower($mimeType));

        if ($extension === null) {
            return ['duration_seconds' => null, 'readable' => false, 'reason' => 'unsupported'];
        }

        try {
            $duration = match ($extension) {
                'wav' => $this->durationFromWav($absolutePath),
                'mp3' => $this->durationFromMp3($absolutePath),
                'flac' => $this->durationFromFlac($absolutePath),
                'm4a' => $this->durationFromM4a($absolutePath),
                default => null,
            };
        } catch (RuntimeException) {
            return ['duration_seconds' => null, 'readable' => false, 'reason' => 'corrupt'];
        }

        if ($duration === null || ! is_finite($duration) || $duration <= 0) {
            return ['duration_seconds' => null, 'readable' => false, 'reason' => 'indeterminate'];
        }

        $seconds = (int) ceil($duration);

        if ($seconds <= 0) {
            return ['duration_seconds' => null, 'readable' => false, 'reason' => 'indeterminate'];
        }

        return ['duration_seconds' => $seconds, 'readable' => true];
    }

    public function exceedsLimit(int $durationSeconds): bool
    {
        $max = max(1, (int) config('karoks.processing.max_audio_duration_seconds', 720));

        return $durationSeconds > $max;
    }

    private function durationFromWav(string $path): ?float
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open wav.');
        }

        try {
            $header = fread($handle, 12);

            if ($header === false || strlen($header) < 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
                throw new RuntimeException('Invalid wav header.');
            }

            $sampleRate = null;
            $byteRate = null;
            $dataSize = null;

            while (! feof($handle)) {
                $chunkHeader = fread($handle, 8);

                if ($chunkHeader === false || strlen($chunkHeader) < 8) {
                    break;
                }

                $chunkId = substr($chunkHeader, 0, 4);
                $chunkSize = unpack('V', substr($chunkHeader, 4, 4))[1] ?? 0;

                if ($chunkId === 'fmt ') {
                    $fmt = fread($handle, min(16, $chunkSize));

                    if ($fmt === false || strlen($fmt) < 16) {
                        throw new RuntimeException('Invalid fmt chunk.');
                    }

                    $sampleRate = unpack('V', substr($fmt, 4, 4))[1] ?? null;
                    $byteRate = unpack('V', substr($fmt, 8, 4))[1] ?? null;
                    $remaining = $chunkSize - min(16, $chunkSize);

                    if ($remaining > 0) {
                        fseek($handle, $remaining, SEEK_CUR);
                    }

                    continue;
                }

                if ($chunkId === 'data') {
                    $dataSize = $chunkSize;

                    break;
                }

                fseek($handle, $chunkSize, SEEK_CUR);
            }

            if ($byteRate !== null && $byteRate > 0 && $dataSize !== null && $dataSize > 0) {
                return $dataSize / $byteRate;
            }

            if ($sampleRate !== null && $sampleRate > 0 && $dataSize !== null && $dataSize > 0) {
                return $dataSize / ($sampleRate * 2);
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    private function durationFromMp3(string $path): ?float
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open mp3.');
        }

        try {
            $header = fread($handle, 10);

            if ($header === false) {
                throw new RuntimeException('Invalid mp3.');
            }

            $offset = 0;

            if (str_starts_with($header, 'ID3') && strlen($header) >= 10) {
                $sizeBytes = (ord($header[6]) << 21) | (ord($header[7]) << 14) | (ord($header[8]) << 7) | ord($header[9]);
                $offset = 10 + $sizeBytes;
            }

            fseek($handle, $offset);

            $totalSamples = 0;
            $sampleRate = null;
            $frames = 0;
            $maxFrames = 50000;

            while (! feof($handle) && $frames < $maxFrames) {
                $sync = fread($handle, 4);

                if ($sync === false || strlen($sync) < 4) {
                    break;
                }

                if ((ord($sync[0]) & 0xFF) !== 0xFF || (ord($sync[1]) & 0xE0) !== 0xE0) {
                    fseek($handle, -3, SEEK_CUR);

                    continue;
                }

                $version = (ord($sync[1]) >> 3) & 0x03;
                $layer = (ord($sync[1]) >> 1) & 0x03;
                $bitrateIndex = (ord($sync[2]) >> 4) & 0x0F;
                $sampleRateIndex = (ord($sync[2]) >> 2) & 0x03;
                $padding = (ord($sync[2]) >> 1) & 0x01;

                if ($layer !== 0x01 || $bitrateIndex === 0 || $bitrateIndex === 15 || $sampleRateIndex === 3) {
                    fseek($handle, -3, SEEK_CUR);

                    continue;
                }

                $bitrateTable = [
                    1 => [0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448],
                    2 => [0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
                    3 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
                ];
                $sampleRateTable = [
                    1 => [44100, 48000, 32000],
                    2 => [22050, 24000, 16000],
                    3 => [11025, 12000, 8000],
                ];

                $bitrateKbps = $bitrateTable[$version][$bitrateIndex] ?? 0;
                $frameSampleRate = $sampleRateTable[$version][$sampleRateIndex] ?? 0;

                if ($bitrateKbps <= 0 || $frameSampleRate <= 0) {
                    fseek($handle, -3, SEEK_CUR);

                    continue;
                }

                $sampleRate = $frameSampleRate;
                $frameLength = (int) floor((144000 * $bitrateKbps) / $frameSampleRate) + $padding;

                if ($frameLength <= 0) {
                    fseek($handle, -3, SEEK_CUR);

                    continue;
                }

                $samplesInFrame = $version === 3 ? 384 : 1152;
                $totalSamples += $samplesInFrame;
                $frames++;
                fseek($handle, $frameLength - 4, SEEK_CUR);
            }

            if ($sampleRate === null || $totalSamples <= 0) {
                return null;
            }

            return $totalSamples / $sampleRate;
        } finally {
            fclose($handle);
        }
    }

    private function durationFromFlac(string $path): ?float
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open flac.');
        }

        try {
            $header = fread($handle, 4);

            if ($header !== 'fLaC') {
                throw new RuntimeException('Invalid flac.');
            }

            while (! feof($handle)) {
                $blockHeader = fread($handle, 4);

                if ($blockHeader === false || strlen($blockHeader) < 4) {
                    break;
                }

                $isLast = (ord($blockHeader[0]) & 0x80) !== 0;
                $blockType = ord($blockHeader[0]) & 0x7F;
                $blockSize = (ord($blockHeader[1]) << 16) | (ord($blockHeader[2]) << 8) | ord($blockHeader[3]);

                if ($blockType === 0 && $blockSize >= 18) {
                    $streamInfo = fread($handle, 18);

                    if ($streamInfo === false || strlen($streamInfo) < 18) {
                        throw new RuntimeException('Invalid streaminfo.');
                    }

                    $high = unpack('N', substr($streamInfo, 10, 4))[1] ?? 0;
                    $lowByte = ord($streamInfo[14]);
                    $totalSamples = (($high & 0x0FFFFFFF) << 8) | $lowByte;
                    $sampleRate = (($high >> 12) & 0xFFFFF);

                    if ($sampleRate <= 0 || $totalSamples <= 0) {
                        return null;
                    }

                    return $totalSamples / $sampleRate;
                }

                fseek($handle, $blockSize, SEEK_CUR);

                if ($isLast) {
                    break;
                }
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    private function durationFromM4a(string $path): ?float
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open m4a.');
        }

        try {
            return $this->findMp4Duration($handle, 0, filesize($path) ?: 0);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function findMp4Duration($handle, int $offset, int $end): ?float
    {
        fseek($handle, $offset);

        while (ftell($handle) < $end) {
            $header = fread($handle, 8);

            if ($header === false || strlen($header) < 8) {
                break;
            }

            $size = unpack('N', substr($header, 0, 4))[1] ?? 0;
            $type = substr($header, 4, 4);

            if ($size < 8) {
                break;
            }

            $payloadStart = ftell($handle);
            $payloadEnd = $offset + $size;

            if ($type === 'mdhd') {
                $version = fread($handle, 1);

                if ($version === false) {
                    return null;
                }

                $versionByte = ord($version);
                fseek($handle, 3, SEEK_CUR);

                if ($versionByte === 1) {
                    $timescaleData = fread($handle, 16);

                    if ($timescaleData === false || strlen($timescaleData) < 16) {
                        return null;
                    }

                    $timescale = unpack('N', substr($timescaleData, 8, 4))[1] ?? 0;
                    $duration = unpack('J', substr($timescaleData, 12, 8))[1] ?? 0;
                } else {
                    $timescaleData = fread($handle, 8);

                    if ($timescaleData === false || strlen($timescaleData) < 8) {
                        return null;
                    }

                    $timescale = unpack('N', substr($timescaleData, 0, 4))[1] ?? 0;
                    $duration = unpack('N', substr($timescaleData, 4, 4))[1] ?? 0;
                }

                if ($timescale <= 0 || $duration <= 0) {
                    return null;
                }

                return $duration / $timescale;
            }

            if (in_array($type, ['moov', 'trak', 'mdia'], true)) {
                $nested = $this->findMp4Duration($handle, $payloadStart, $payloadEnd);

                if ($nested !== null) {
                    return $nested;
                }
            }

            fseek($handle, $payloadEnd);
            $offset = $payloadEnd;
        }

        return null;
    }
}
