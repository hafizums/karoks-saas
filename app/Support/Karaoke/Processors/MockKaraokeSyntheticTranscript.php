<?php

namespace App\Support\Karaoke\Processors;

class MockKaraokeSyntheticTranscript
{
    public const DISCLOSURE = 'Development mock result: the source audio was copied for playback. Vocal removal and lyric transcription were not performed.';

    /**
     * Build a deterministic, parser-valid demo transcript with generic non-copyrighted text.
     *
     * @return array{version: int, lines: list<array<string, mixed>>}
     */
    public static function build(float $durationSeconds = 27.0): array
    {
        $lines = [
            ['id' => 'line-1', 'words' => ['Welcome', 'to', 'this', 'practice', 'track']],
            ['id' => 'line-2', 'words' => ['Tap', 'each', 'word', 'as', 'you', 'sing', 'along']],
            ['id' => 'line-3', 'words' => ['This', 'is', 'a', 'development', 'mock', 'result']],
            ['id' => 'line-4', 'words' => ['No', 'vocals', 'were', 'removed', 'for', 'real']],
            ['id' => 'line-5', 'words' => ['Lyrics', 'here', 'are', 'generic', 'placeholders', 'only']],
            ['id' => 'line-6', 'words' => ['Have', 'fun', 'testing', 'your', 'karaoke', 'project']],
        ];

        $lineCount = count($lines);
        $segment = max(1.0, $durationSeconds / $lineCount);
        $parsedLines = [];
        $wordCounter = 1;

        foreach ($lines as $index => $line) {
            $lineStart = round(0.5 + ($index * $segment), 1);
            $lineEnd = round(min($durationSeconds, $lineStart + $segment - 0.2), 1);
            $words = [];
            $wordCount = count($line['words']);
            $wordDuration = ($lineEnd - $lineStart) / max(1, $wordCount);
            $cursor = $lineStart;

            foreach ($line['words'] as $wordIndex => $text) {
                $start = round($cursor, 1);
                $end = round($wordIndex === $wordCount - 1 ? $lineEnd : $cursor + $wordDuration, 1);
                $words[] = [
                    'id' => 'word-'.$wordCounter,
                    'text' => $text,
                    'start' => $start,
                    'end' => $end,
                ];
                $wordCounter++;
                $cursor = $end;
            }

            $parsedLines[] = [
                'id' => $line['id'],
                'start' => $lineStart,
                'end' => $lineEnd,
                'words' => $words,
            ];
        }

        return [
            'version' => 1,
            'lines' => $parsedLines,
        ];
    }
}
