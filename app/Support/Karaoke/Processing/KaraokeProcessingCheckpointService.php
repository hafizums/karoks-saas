<?php

namespace App\Support\Karaoke\Processing;

use App\Enums\KaraokeProjectStatus;
use App\Models\KaraokeProject;
use Illuminate\Support\Facades\DB;

class KaraokeProcessingCheckpointService
{
    public function __construct(
        private readonly KaraokeProcessingHeartbeatService $heartbeatService,
    ) {}

    public function clearForFreshAttempt(KaraokeProject $project): void
    {
        $project->forceFill([
            'provider_checkpoint_run_id' => null,
            'provider_checkpoint_attempt' => null,
            'wavespeed_prediction_id' => null,
            'provider_separation_completed_at' => null,
            'wavespeed_prediction_failed_at' => null,
            'provider_transcript_checkpoint' => null,
        ])->save();
    }

    public function bindRun(KaraokeProject $project, string $runId, string $driver): void
    {
        DB::transaction(function () use ($project, $runId, $driver): void {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->canBindRun($locked, $runId)) {
                return;
            }

            $locked->forceFill([
                'processing_driver' => $driver,
                'provider_checkpoint_run_id' => $runId,
                'provider_checkpoint_attempt' => (int) $locked->processing_attempts,
            ])->save();
        });
    }

    public function savePredictionId(KaraokeProject $project, string $runId, string $predictionId): void
    {
        DB::transaction(function () use ($project, $runId, $predictionId): void {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->canWriteCheckpoint($locked, $runId)) {
                return;
            }

            $locked->forceFill([
                'wavespeed_prediction_id' => $predictionId,
                'wavespeed_prediction_failed_at' => null,
            ])->save();

            $this->heartbeatService->touchLocked($locked, $runId);
            $locked->save();
        });
    }

    public function markSeparationCompleted(KaraokeProject $project, string $runId): void
    {
        DB::transaction(function () use ($project, $runId): void {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->canWriteCheckpoint($locked, $runId)) {
                return;
            }

            $locked->forceFill([
                'provider_separation_completed_at' => now(),
                'wavespeed_prediction_failed_at' => null,
            ])->save();

            $this->heartbeatService->touchLocked($locked, $runId);
            $locked->save();
        });
    }

    /**
     * @param  array{version: int, lines: list<array<string, mixed>>}  $transcript
     */
    public function saveTranscriptCheckpoint(KaraokeProject $project, string $runId, array $transcript): void
    {
        DB::transaction(function () use ($project, $runId, $transcript): void {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->canWriteCheckpoint($locked, $runId)) {
                return;
            }

            $locked->forceFill([
                'provider_transcript_checkpoint' => $transcript,
            ])->save();

            $this->heartbeatService->touchLocked($locked, $runId);
            $locked->save();
        });
    }

    public function invalidateSeparationCheckpoint(KaraokeProject $project, string $runId): void
    {
        DB::transaction(function () use ($project, $runId): void {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->canWriteCheckpoint($locked, $runId)) {
                return;
            }

            $locked->forceFill([
                'wavespeed_prediction_id' => null,
                'provider_separation_completed_at' => null,
                'wavespeed_prediction_failed_at' => now(),
            ])->save();
        });
    }

    public function clearAfterCompletion(KaraokeProject $project, string $runId): void
    {
        DB::transaction(function () use ($project, $runId): void {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->canClearCheckpoint($locked, $runId)) {
                return;
            }

            $locked->forceFill([
                'provider_checkpoint_run_id' => null,
                'provider_checkpoint_attempt' => null,
                'wavespeed_prediction_id' => null,
                'provider_separation_completed_at' => null,
                'wavespeed_prediction_failed_at' => null,
                'provider_transcript_checkpoint' => null,
            ])->save();
        });
    }

    public function clearAfterCancellation(KaraokeProject $project): void
    {
        $project->forceFill([
            'provider_checkpoint_run_id' => null,
            'provider_checkpoint_attempt' => null,
            'wavespeed_prediction_id' => null,
            'provider_separation_completed_at' => null,
            'wavespeed_prediction_failed_at' => null,
            'provider_transcript_checkpoint' => null,
        ])->save();
    }

    public function hasReusableSeparation(KaraokeProject $project): bool
    {
        if (! $this->canReuseCheckpoint($project)) {
            return false;
        }

        return is_string($project->wavespeed_prediction_id)
            && $project->wavespeed_prediction_id !== ''
            && $project->wavespeed_prediction_failed_at === null;
    }

    /**
     * @return array{version: int, lines: list<array<string, mixed>>}|null
     */
    public function reusableTranscript(KaraokeProject $project): ?array
    {
        if (! $this->canReuseCheckpoint($project)) {
            return null;
        }

        $checkpoint = $project->provider_transcript_checkpoint;

        if (! is_array($checkpoint)) {
            return null;
        }

        return $checkpoint;
    }

    public function checkpointMatches(KaraokeProject $project, string $runId): bool
    {
        return $this->canWriteCheckpoint($project, $runId);
    }

    private function canBindRun(KaraokeProject $project, string $runId): bool
    {
        return $project->status === KaraokeProjectStatus::Processing
            && $project->processing_run_id === $runId;
    }

    private function canWriteCheckpoint(KaraokeProject $project, string $runId): bool
    {
        if ($project->status !== KaraokeProjectStatus::Processing) {
            return false;
        }

        if ($project->processing_run_id !== $runId) {
            return false;
        }

        if ($project->provider_checkpoint_run_id === null || $project->provider_checkpoint_run_id !== $runId) {
            return false;
        }

        if ($project->provider_checkpoint_attempt === null) {
            return false;
        }

        return (int) $project->provider_checkpoint_attempt === (int) $project->processing_attempts;
    }

    private function canClearCheckpoint(KaraokeProject $project, string $runId): bool
    {
        return $project->processing_run_id === $runId;
    }

    private function canReuseCheckpoint(KaraokeProject $project): bool
    {
        if ($project->status !== KaraokeProjectStatus::Processing) {
            return false;
        }

        if ($project->processing_run_id === null) {
            return false;
        }

        return $this->canWriteCheckpoint($project, (string) $project->processing_run_id);
    }
}
