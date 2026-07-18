<?php

namespace App\Support;

use App\Models\KaraokeProject;
use Illuminate\Support\Str;

class KaraokeProjectExporter
{
    public const SCHEMA = 'karoks-project';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function buildPayload(KaraokeProject $project): array
    {
        $transcript = KaraokeTranscriptParser::parse($project->transcript);

        if ($transcript === null) {
            throw new \RuntimeException('Project transcript is not exportable.');
        }

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'exportedAt' => now()->toIso8601String(),
            'project' => [
                'title' => $project->title,
                'artist' => $project->artist,
                'transcript' => $transcript,
                'theme' => KaraokeThemeParser::parse($project->theme),
            ],
        ];
    }

    public static function downloadFilename(KaraokeProject $project): string
    {
        $slug = Str::slug($project->title);
        $slug = $slug !== '' ? Str::limit($slug, 48, '') : 'karaoke-project';

        return $slug.'-karoks-export.json';
    }
}
