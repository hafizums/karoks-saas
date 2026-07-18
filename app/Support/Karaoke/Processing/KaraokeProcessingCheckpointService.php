<?php

namespace App\Support\Karaoke\Processing;

use App\Models\KaraokeProject;
use Illuminate\Support\Facades\DB;

class KaraokeProcessingCheckpointService
{
    public function clearForFreshAttempt(KaraokeProject $project): void
    {
        $project->forceFill([
            'processing_driver' => null,
            'provider_checkpoint_run_id' => null,
            'provider_checkpoint_attempt' => null,
            'wavespeed_prediction_id' => null,
            'provider_separation_completed_at' => null,
            'provider_transcript_checkpoint' => null,
        ])->save();
    }

    public function bindRun(KaraokeProject $project, string $runId, string $driver): void
    {
        $project->forceFill([
            'processing_driver' => $driver,
            'provider_checkpoint_run_id' => $runId,
            'provider_checkpoint_attempt' => (int) $project->processing_attempts,
        ])->save();
    }

    public function savePredictionId(KaraokeProject $project, string $runId, string $predictionId): void
    {
        DB::transaction(function () use ($project, $runId, $predictionId): void {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->checkpointMatches($locked, $runId)) {
                return;
            }

            $locked->forceFill([
                'wavespeed_prediction_id' => $predictionId,
            ])->save();
        });
    }

    public function markSeparationCompleted(KaraokeProject $project, string $runId): void
    {
        DB::transaction(function () use ($project, $runId): void {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->checkpointMatches($locked, $runId)) {
                return;
            }

            $locked->forceFill([
                'provider_separation_completed_at' => now(),
            ])->save();
        });
    }

    /**
     * @param  array{version: int, lines: list<array<string, mixed>>}  $transcript
     */
    public function saveTranscriptCheckpoint(KaraokeProject $project, string $runId, array $transcript): void
    {
        DB::transaction(function () use ($project, $runId, $transcript): void {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->checkpointMatches($locked, $runId)) {
                return;
            }

            $locked->forceFill([
                'provider_transcript_checkpoint' => $transcript,
            ])->save();
        });
    }

    public function hasReusableSeparation(KaraokeProject $project): bool
    {
        return is_string($project->wavespeed_prediction_id)
            && $project->wavespeed_prediction_id !== '';
    }

    /**
     * @return array{version: int, lines: list<array<string, mixed>>}|null
     */
    public function reusableTranscript(KaraokeProject $project): ?array
    {
        $checkpoint = $project->provider_transcript_checkpoint;

        if (! is_array($checkpoint)) {
            return null;
        }

        return $checkpoint;
    }

    public function checkpointMatches(KaraokeProject $project, string $runId): bool
    {
        if ($project->processing_run_id !== $runId) {
            return false;
        }

        if ($project->provider_checkpoint_run_id !== null && $project->provider_checkpoint_run_id !== $runId) {
            return false;
        }

        if ($project->provider_checkpoint_attempt !== null
            && (int) $project->provider_checkpoint_attempt !== (int) $project->processing_attempts) {
            return false;
        }

        return true;
    }
}
