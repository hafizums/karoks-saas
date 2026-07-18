<?php

namespace App\Support;

use App\Exceptions\KaraokeEditorConflictException;
use App\Models\KaraokeProject;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class KaraokeProjectEditorService
{
    /**
     * @param  array{revision: int, title?: string, artist?: string|null, theme?: array<string, mixed>, words?: array<string, string>}  $payload
     * @return array<string, mixed>
     */
    public function update(KaraokeProject $project, array $payload): array
    {
        $currentTranscript = KaraokeTranscriptParser::parse($project->transcript);

        if ($currentTranscript === null) {
            throw new InvalidArgumentException('Lyrics are not ready for editing.');
        }

        $expectedRevision = (int) ($payload['revision'] ?? 0);

        return DB::transaction(function () use ($project, $payload, $currentTranscript, $expectedRevision): array {
            /** @var KaraokeProject $locked */
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->editor_revision !== $expectedRevision) {
                throw new KaraokeEditorConflictException($this->normalizedState($locked));
            }

            $title = array_key_exists('title', $payload)
                ? $this->sanitizeTitle($payload['title'])
                : $locked->title;

            $artist = array_key_exists('artist', $payload)
                ? $this->sanitizeArtist($payload['artist'])
                : $locked->artist;

            $theme = array_key_exists('theme', $payload)
                ? KaraokeThemeParser::parseStrict($payload['theme'])
                : KaraokeThemeParser::parse($locked->theme);

            $transcript = $currentTranscript;

            if (! empty($payload['words']) && is_array($payload['words'])) {
                $transcript = KaraokeTranscriptEditor::applyWordTexts($transcript, $payload['words']);
            }

            $locked->fill([
                'title' => $title,
                'artist' => $artist,
                'theme' => $theme,
                'transcript' => $transcript,
            ]);

            $locked->editor_revision = (int) $locked->editor_revision + 1;
            $locked->save();

            return $this->normalizedState($locked->fresh());
        });
    }

    /**
     * @param  array{revision: int, title: string, artist: ?string, theme: array<string, string>, wordTexts: array<string, string>}  $import
     * @return array<string, mixed>
     */
    public function import(KaraokeProject $project, array $import, int $expectedRevision): array
    {
        return $this->update($project, [
            'revision' => $expectedRevision,
            'title' => $import['title'],
            'artist' => $import['artist'],
            'theme' => $import['theme'],
            'words' => $import['wordTexts'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizedState(KaraokeProject $project): array
    {
        $transcript = KaraokeTranscriptParser::parse($project->transcript);

        return [
            'revision' => (int) $project->editor_revision,
            'title' => $project->title,
            'artist' => $project->artist,
            'theme' => KaraokeThemeParser::parse($project->theme),
            'lines' => $transcript['lines'] ?? [],
        ];
    }

    private function sanitizeTitle(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Title must be a string.');
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 191) {
            throw new InvalidArgumentException('Title must be between 1 and 191 characters.');
        }

        return $trimmed;
    }

    private function sanitizeArtist(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Artist must be a string.');
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (strlen($trimmed) > 191) {
            throw new InvalidArgumentException('Artist must be 191 characters or fewer.');
        }

        return $trimmed;
    }
}
