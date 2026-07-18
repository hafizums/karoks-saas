<?php

namespace App\Support\Karaoke\Providers;

use App\Support\KaraokeTranscriptParser;

class KaraokeTranscriptNormalizer
{
    private const MAX_WORDS_PER_LINE = 8;

    private const MAX_CHARS_PER_LINE = 48;

    private const SILENCE_GAP_SECONDS = 1.2;

    private const MIN_WORDS_FOR_SENTENCE_BREAK = 3;

    public function __construct(
        private readonly KaraokeProviderErrorMapper $errors,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     * @return array{version: int, lines: list<array<string, mixed>>}
     */
    public function normalize(array $response, float $durationSeconds, string $projectPublicId): array
    {
        if (! is_finite($durationSeconds) || $durationSeconds <= 0) {
            throw $this->errors->invalidProviderOutput();
        }

        $rawWords = is_array($response['words'] ?? null) ? $response['words'] : [];
        $words = [];

        foreach ($rawWords as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'word') {
                continue;
            }

            $text = is_string($entry['text'] ?? null) ? trim($entry['text']) : '';

            if ($text === '' || strlen($text) > KaraokeTranscriptParser::MAX_WORD_TEXT_LENGTH) {
                continue;
            }

            $start = $this->asFiniteNumber($entry['start'] ?? null);
            $end = $this->asFiniteNumber($entry['end'] ?? null);

            if ($start === null || $end === null || ! ($start >= 0 && $start < $end && $end <= $durationSeconds + 0.05)) {
                continue;
            }

            $words[] = [
                'text' => $text,
                'start' => round($start, 3),
                'end' => round(min($end, $durationSeconds), 3),
            ];
        }

        usort($words, fn (array $a, array $b): int => $a['start'] <=> $b['start'] ?: $a['end'] <=> $b['end']);

        if ($words === []) {
            throw $this->errors->noLyricsFound();
        }

        if (count($words) > KaraokeTranscriptParser::MAX_WORDS_TOTAL) {
            throw $this->errors->invalidProviderOutput();
        }

        $lines = [];
        $current = [];

        foreach ($words as $word) {
            if ($current === []) {
                $current[] = $word;

                continue;
            }

            $previous = $current[count($current) - 1];
            $gap = $word['start'] - $previous['end'];
            $nextChars = array_sum(array_map(fn (array $item): int => strlen($item['text']), $current))
                + strlen($word['text'])
                + count($current);

            $breakForGap = $gap > self::SILENCE_GAP_SECONDS;
            $breakForCount = count($current) >= self::MAX_WORDS_PER_LINE;
            $breakForChars = $nextChars > self::MAX_CHARS_PER_LINE;
            $breakForSentence = count($current) >= self::MIN_WORDS_FOR_SENTENCE_BREAK
                && $this->isSentenceEnding($previous['text']);

            if ($breakForGap || $breakForCount || $breakForChars || $breakForSentence) {
                $lines[] = $this->buildLine($current, $projectPublicId, count($lines) + 1);
                $current = [];
            }

            $current[] = $word;
        }

        if ($current !== []) {
            $lines[] = $this->buildLine($current, $projectPublicId, count($lines) + 1);
        }

        if (count($lines) > KaraokeTranscriptParser::MAX_LINES) {
            throw $this->errors->invalidProviderOutput();
        }

        $transcript = [
            'version' => KaraokeTranscriptParser::SUPPORTED_VERSION,
            'lines' => $lines,
        ];

        if (KaraokeTranscriptParser::parse($transcript) === null) {
            throw $this->errors->invalidProviderOutput();
        }

        return $transcript;
    }

    /**
     * @param  list<array{text: string, start: float, end: float}>  $words
     * @return array{id: string, start: float, end: float, words: list<array{id: string, text: string, start: float, end: float}>}
     */
    private function buildLine(array $words, string $projectPublicId, int $lineNumber): array
    {
        $lineId = $this->deterministicId('line', $projectPublicId, (string) $lineNumber);
        $parsedWords = [];

        foreach ($words as $index => $word) {
            $parsedWords[] = [
                'id' => $this->deterministicId('word', $projectPublicId, $lineNumber.'-'.($index + 1)),
                'text' => $word['text'],
                'start' => $word['start'],
                'end' => $word['end'],
            ];
        }

        return [
            'id' => $lineId,
            'start' => $parsedWords[0]['start'],
            'end' => $parsedWords[count($parsedWords) - 1]['end'],
            'words' => $parsedWords,
        ];
    }

    private function deterministicId(string $prefix, string $projectPublicId, string $suffix): string
    {
        $hash = substr(hash('sha256', $projectPublicId.'|'.$prefix.'|'.$suffix), 0, 16);

        return $prefix.'-'.$hash;
    }

    private function isSentenceEnding(string $text): bool
    {
        return (bool) preg_match('/[.!?…]["\')\]]*$/u', trim($text));
    }

    private function asFiniteNumber(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        if (! is_finite((float) $value)) {
            return null;
        }

        return (float) $value;
    }
}
