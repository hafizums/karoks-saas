<?php

namespace App\Support;

use InvalidArgumentException;

class KaraokeTranscriptEditor
{
    /**
     * @param  array{version: int, lines: list<array{id: string, start: float, end: float, words: list<array{id: string, text: string, start: float, end: float}>}>}  $transcript
     * @param  array<string, string>  $wordTexts
     * @return array{version: int, lines: list<array{id: string, start: float, end: float, words: list<array{id: string, text: string, start: float, end: float}>}>}
     */
    public static function applyWordTexts(array $transcript, array $wordTexts): array
    {
        if ($wordTexts === []) {
            return $transcript;
        }

        $knownWordIds = self::wordIdIndex($transcript);

        foreach ($wordTexts as $wordId => $text) {
            if (! is_string($wordId) || ! is_string($text)) {
                throw new InvalidArgumentException('Invalid word edit payload.');
            }

            if (! isset($knownWordIds[$wordId])) {
                throw new InvalidArgumentException("Unknown word id [{$wordId}].");
            }

            $trimmed = trim($text);
            if ($trimmed === '') {
                throw new InvalidArgumentException("Word text for [{$wordId}] cannot be empty.");
            }

            if (strlen($trimmed) > KaraokeTranscriptParser::MAX_WORD_TEXT_LENGTH) {
                throw new InvalidArgumentException("Word text for [{$wordId}] is too long.");
            }
        }

        $lines = [];

        foreach ($transcript['lines'] as $line) {
            $words = [];

            foreach ($line['words'] as $word) {
                $words[] = array_key_exists($word['id'], $wordTexts)
                    ? array_merge($word, ['text' => trim($wordTexts[$word['id']])])
                    : $word;
            }

            $lines[] = array_merge($line, ['words' => $words]);
        }

        $updated = [
            'version' => $transcript['version'],
            'lines' => $lines,
        ];

        $parsed = KaraokeTranscriptParser::parse($updated);

        if ($parsed === null) {
            throw new InvalidArgumentException('Updated transcript failed validation.');
        }

        return $parsed;
    }

    /**
     * @param  array{version: int, lines: list<array{id: string, start: float, end: float, words: list<array{id: string, text: string, start: float, end: float}>}>}  $transcript
     * @return array<string, true>
     */
    public static function wordIdIndex(array $transcript): array
    {
        $index = [];

        foreach ($transcript['lines'] as $line) {
            foreach ($line['words'] as $word) {
                $index[$word['id']] = true;
            }
        }

        return $index;
    }

    /**
     * @param  array{version: int, lines: list<array{id: string, start: float, end: float, words: list<array{id: string, text: string, start: float, end: float}>}>}  $current
     * @param  array{version: int, lines: list<array{id: string, start: float, end: float, words: list<array{id: string, text: string, start: float, end: float}>}>}  $candidate
     */
    public static function timingSkeletonMatches(array $current, array $candidate): bool
    {
        if (count($current['lines']) !== count($candidate['lines'])) {
            return false;
        }

        foreach ($current['lines'] as $index => $line) {
            $other = $candidate['lines'][$index];

            if ($line['id'] !== $other['id']) {
                return false;
            }

            if ($line['start'] !== $other['start'] || $line['end'] !== $other['end']) {
                return false;
            }

            if (count($line['words']) !== count($other['words'])) {
                return false;
            }

            foreach ($line['words'] as $wordIndex => $word) {
                $otherWord = $other['words'][$wordIndex];

                if ($word['id'] !== $otherWord['id']) {
                    return false;
                }

                if ($word['start'] !== $otherWord['start'] || $word['end'] !== $otherWord['end']) {
                    return false;
                }
            }
        }

        return true;
    }
}
