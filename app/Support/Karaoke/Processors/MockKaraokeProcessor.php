<?php

namespace App\Support\Karaoke\Processors;

use App\Contracts\KaraokeProcessor;
use App\Enums\KaraokeProcessingStage;
use App\Models\KaraokeProject;
use App\Rules\ValidKaraokeAudio;
use App\Support\KaraokeProcessingProgress;
use App\Support\KaraokeProcessingResult;
use App\Support\KaraokeStorage;
use App\Support\KaraokeThemeParser;
use Closure;
use RuntimeException;

class MockKaraokeProcessor implements KaraokeProcessor
{
    /**
     * @param  Closure(KaraokeProcessingProgress): void  $reportProgress
     */
    public function process(KaraokeProject $project, string $processingRunId, Closure $reportProgress): KaraokeProcessingResult
    {
        unset($processingRunId);

        $delayMs = max(0, (int) config('karoks.processing.mock_stage_delay_ms', 0));
        $stages = KaraokeProcessingStage::ordered();
        array_pop($stages);

        foreach ($stages as $stage) {
            $reportProgress(new KaraokeProcessingProgress($stage, $stage->progress()));

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $disk = KaraokeStorage::disk();

        if (! $project->source_path || ! $disk->exists($project->source_path)) {
            throw new RuntimeException('Source audio is missing.');
        }

        $extension = ValidKaraokeAudio::safeExtensionFromMime($project->mime_type)
            ?? pathinfo($project->source_path, PATHINFO_EXTENSION);

        if (! is_string($extension) || $extension === '') {
            throw new RuntimeException('Unable to determine a safe audio extension.');
        }

        $instrumentalPath = $project->storageDirectory().'/instrumental.'.$extension;
        $sourceContents = $disk->get($project->source_path);

        if ($sourceContents === null) {
            throw new RuntimeException('Unable to read source audio.');
        }

        $disk->put($instrumentalPath, $sourceContents);

        $duration = max(10.0, (float) ($project->duration_seconds ?? 27));
        $transcript = MockKaraokeSyntheticTranscript::build($duration);
        $theme = KaraokeThemeParser::parse([]);

        $reportProgress(new KaraokeProcessingProgress(KaraokeProcessingStage::Completed, 100));

        return new KaraokeProcessingResult(
            instrumentalPath: $instrumentalPath,
            instrumentalMimeType: $project->mime_type,
            transcript: $transcript,
            theme: $theme,
            disclosure: MockKaraokeSyntheticTranscript::DISCLOSURE,
            simulated: true,
        );
    }
}
