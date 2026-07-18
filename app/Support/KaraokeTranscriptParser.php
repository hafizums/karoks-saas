<?php

namespace App\Support;

use InvalidArgumentException;

class KaraokeTranscriptParser
{
    public const SUPPORTED_VERSION = 1;

    /**
     * @return array{version: int, lines: list<array{id: string, start: float, end: float, words: list<array{id: string, text: string, start: float, end: float}>}>}|null
     */
    public static function parse(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        try {
            $version = $input['version'] ?? null;
            if ($version !== self::SUPPORTED_VERSION) {
                return null;
            }

            if (! isset($input['lines']) || ! is_array($input['lines'])) {
                return null;
            }

            $lines = self::parseLines($input['lines']);

            if ($lines === null) {
                return null;
            }

            usort($lines, fn (array $a, array $b): int => $a['start'] <=> $b['start'] ?: strcmp($a['id'], $b['id']));

            return [
                'version' => self::SUPPORTED_VERSION,
                'lines' => $lines,
            ];
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  list<mixed>  $rawLines
     * @return list<array{id: string, start: float, end: float, words: list<array{id: string, text: string, start: float, end: float}>}>|null
     */
    private static function parseLines(array $rawLines): ?array
    {
        if ($rawLines === []) {
            return null;
        }

        $lines = [];
        $lineIds = [];

        foreach ($rawLines as $rawLine) {
            if (! is_array($rawLine)) {
                return null;
            }

            $lineId = self::requireString($rawLine['id'] ?? null, 'line id');
            if (isset($lineIds[$lineId])) {
                return null;
            }
            $lineIds[$lineId] = true;

            $lineStart = self::requireTime($rawLine['start'] ?? null, 'line start');
            $lineEnd = self::requireTime($rawLine['end'] ?? null, 'line end');
            if ($lineEnd < $lineStart) {
                return null;
            }

            $words = self::parseWords($rawLine['words'] ?? null, $lineStart, $lineEnd);
            if ($words === null) {
                return null;
            }

            $lines[] = [
                'id' => $lineId,
                'start' => $lineStart,
                'end' => $lineEnd,
                'words' => $words,
            ];
        }

        return $lines;
    }

    /**
     * @return list<array{id: string, text: string, start: float, end: float}>|null
     */
    private static function parseWords(mixed $rawWords, float $lineStart, float $lineEnd): ?array
    {
        if (! is_array($rawWords) || $rawWords === []) {
            return null;
        }

        $words = [];
        $wordIds = [];

        foreach ($rawWords as $rawWord) {
            if (! is_array($rawWord)) {
                return null;
            }

            $wordId = self::requireString($rawWord['id'] ?? null, 'word id');
            if (isset($wordIds[$wordId])) {
                return null;
            }
            $wordIds[$wordId] = true;

            $text = self::requireString($rawWord['text'] ?? null, 'word text');
            $wordStart = self::requireTime($rawWord['start'] ?? null, 'word start');
            $wordEnd = self::requireTime($rawWord['end'] ?? null, 'word end');

            if ($wordEnd < $wordStart) {
                return null;
            }

            if ($wordStart < $lineStart || $wordEnd > $lineEnd) {
                return null;
            }

            $words[] = [
                'id' => $wordId,
                'text' => $text,
                'start' => $wordStart,
                'end' => $wordEnd,
            ];
        }

        usort($words, fn (array $a, array $b): int => $a['start'] <=> $b['start'] ?: strcmp($a['id'], $b['id']));

        return $words;
    }

    private static function requireString(mixed $value, string $label): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Invalid {$label}.");
        }

        return $value;
    }

    private static function requireTime(mixed $value, string $label): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("Invalid {$label}.");
        }

        $time = (float) $value;

        if (! is_finite($time) || $time < 0) {
            throw new InvalidArgumentException("Invalid {$label}.");
        }

        return $time;
    }
}
