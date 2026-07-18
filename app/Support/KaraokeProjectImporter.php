<?php

namespace App\Support;

use App\Models\KaraokeProject;
use InvalidArgumentException;

class KaraokeProjectImporter
{
    /**
     * @return array{title: string, artist: ?string, theme: array<string, string>, wordTexts: array<string, string>}
     */
    public static function parseImportPayload(mixed $payload, KaraokeProject $project): array
    {
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Import file must contain a JSON object.');
        }

        if (($payload['schema'] ?? null) !== KaraokeProjectExporter::SCHEMA) {
            throw new InvalidArgumentException('Unsupported import schema.');
        }

        if (($payload['version'] ?? null) !== KaraokeProjectExporter::VERSION) {
            throw new InvalidArgumentException('Unsupported import version.');
        }

        if (! isset($payload['project']) || ! is_array($payload['project'])) {
            throw new InvalidArgumentException('Import project payload is missing.');
        }

        $projectPayload = $payload['project'];
        $currentTranscript = KaraokeTranscriptParser::parse($project->transcript);

        if ($currentTranscript === null) {
            throw new InvalidArgumentException('Current project transcript is not editable.');
        }

        $importTranscript = KaraokeTranscriptParser::parse($projectPayload['transcript'] ?? null);

        if ($importTranscript === null) {
            throw new InvalidArgumentException('Imported transcript is invalid.');
        }

        if (! KaraokeTranscriptEditor::timingSkeletonMatches($currentTranscript, $importTranscript)) {
            throw new InvalidArgumentException('Imported timing skeleton does not match this project.');
        }

        $title = self::requireTitle($projectPayload['title'] ?? null);
        $artist = self::optionalArtist($projectPayload['artist'] ?? null);
        $theme = KaraokeThemeParser::parseStrict($projectPayload['theme'] ?? null);
        $wordTexts = self::extractWordTexts($importTranscript);

        return [
            'title' => $title,
            'artist' => $artist,
            'theme' => $theme,
            'wordTexts' => $wordTexts,
        ];
    }

    private static function requireTitle(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Imported title is invalid.');
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 191) {
            throw new InvalidArgumentException('Imported title is invalid.');
        }

        return $trimmed;
    }

    private static function optionalArtist(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Imported artist is invalid.');
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (strlen($trimmed) > 191) {
            throw new InvalidArgumentException('Imported artist is invalid.');
        }

        return $trimmed;
    }

    /**
     * @param  array{version: int, lines: list<array{id: string, start: float, end: float, words: list<array{id: string, text: string, start: float, end: float}>}>}  $transcript
     * @return array<string, string>
     */
    private static function extractWordTexts(array $transcript): array
    {
        $wordTexts = [];

        foreach ($transcript['lines'] as $line) {
            foreach ($line['words'] as $word) {
                $wordTexts[$word['id']] = $word['text'];
            }
        }

        return $wordTexts;
    }
}
