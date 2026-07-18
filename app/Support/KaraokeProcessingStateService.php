<?php

namespace App\Support;

use App\Enums\KaraokeProcessingStage;
use App\Enums\KaraokeProjectStatus;
use App\Jobs\ProcessKaraokeProject;
use App\Models\KaraokeProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class KaraokeProcessingStateService
{
    public const SAFE_ERROR_CODES = [
        'processing_failed',
        'source_missing',
        'unsupported_audio',
        'cancelled',
    ];

    /**
     * @return array{dispatched: bool, run_id: string|null}
     */
    public function queueForProcessing(KaraokeProject $project): array
    {
        return DB::transaction(function () use ($project) {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null) {
                return ['dispatched' => false, 'run_id' => null];
            }

            if (in_array($locked->status, [KaraokeProjectStatus::Queued, KaraokeProjectStatus::Processing], true)) {
                return ['dispatched' => false, 'run_id' => $locked->processing_run_id];
            }

            if (! in_array($locked->status, [KaraokeProjectStatus::Uploaded, KaraokeProjectStatus::Cancelled], true)) {
                throw new RuntimeException('Project cannot be queued for processing from its current state.');
            }

            $runId = (string) Str::uuid();

            $locked->forceFill([
                'status' => KaraokeProjectStatus::Queued,
                'processing_run_id' => $runId,
                'processing_stage' => null,
                'progress' => 0,
                'queued_at' => now(),
                'processing_started_at' => null,
                'processing_completed_at' => null,
                'processing_failed_at' => null,
                'error_code' => null,
                'error_message' => null,
                'instrumental_path' => null,
                'instrumental_mime_type' => null,
                'transcript' => null,
                'theme' => null,
                'processing_attempts' => (int) $locked->processing_attempts + 1,
            ])->save();

            $this->removePartialInstrumental($locked);

            ProcessKaraokeProject::dispatch($locked->id, $runId)->afterCommit();

            return ['dispatched' => true, 'run_id' => $runId];
        });
    }

    /**
     * @return array{dispatched: bool, run_id: string|null}
     */
    public function retryProcessing(KaraokeProject $project): array
    {
        return DB::transaction(function () use ($project) {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null) {
                return ['dispatched' => false, 'run_id' => null];
            }

            if (in_array($locked->status, [KaraokeProjectStatus::Queued, KaraokeProjectStatus::Processing], true)) {
                return ['dispatched' => false, 'run_id' => $locked->processing_run_id];
            }

            if ($locked->status !== KaraokeProjectStatus::Failed || ! $this->isRetryable($locked)) {
                throw new RuntimeException('Project cannot be retried from its current state.');
            }

            $runId = (string) Str::uuid();

            $locked->forceFill([
                'status' => KaraokeProjectStatus::Queued,
                'processing_run_id' => $runId,
                'processing_stage' => null,
                'progress' => 0,
                'queued_at' => now(),
                'processing_started_at' => null,
                'processing_completed_at' => null,
                'processing_failed_at' => null,
                'error_code' => null,
                'error_message' => null,
                'instrumental_path' => null,
                'instrumental_mime_type' => null,
                'transcript' => null,
                'theme' => null,
                'processing_attempts' => (int) $locked->processing_attempts + 1,
            ])->save();

            $this->removePartialInstrumental($locked);

            ProcessKaraokeProject::dispatch($locked->id, $runId)->afterCommit();

            return ['dispatched' => true, 'run_id' => $runId];
        });
    }

    public function cancelProcessing(KaraokeProject $project): bool
    {
        return DB::transaction(function () use ($project) {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null) {
                return false;
            }

            if (! in_array($locked->status, [KaraokeProjectStatus::Queued, KaraokeProjectStatus::Processing], true)) {
                return false;
            }

            $locked->forceFill([
                'status' => KaraokeProjectStatus::Cancelled,
                'processing_stage' => null,
                'progress' => 0,
                'processing_failed_at' => null,
                'error_code' => 'cancelled',
                'error_message' => 'Processing was cancelled.',
            ])->save();

            $this->removePartialInstrumental($locked);

            return true;
        });
    }

    public function beginProcessingRun(KaraokeProject $project, string $runId): bool
    {
        return DB::transaction(function () use ($project, $runId) {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->runIsActive($locked, $runId)) {
                return false;
            }

            if ($locked->status === KaraokeProjectStatus::Processing && $locked->processing_run_id === $runId) {
                return true;
            }

            if ($locked->status !== KaraokeProjectStatus::Queued) {
                return false;
            }

            $locked->forceFill([
                'status' => KaraokeProjectStatus::Processing,
                'processing_started_at' => now(),
                'processing_stage' => KaraokeProcessingStage::Preparing->value,
                'progress' => KaraokeProcessingStage::Preparing->progress(),
            ])->save();

            return true;
        });
    }

    public function recordProgress(KaraokeProject $project, string $runId, KaraokeProcessingStage $stage, int $progress): bool
    {
        return DB::transaction(function () use ($project, $runId, $stage, $progress) {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->runIsActive($locked, $runId)) {
                return false;
            }

            if ($locked->status !== KaraokeProjectStatus::Processing) {
                return false;
            }

            $currentStage = $locked->processing_stage
                ? KaraokeProcessingStage::tryFrom($locked->processing_stage)
                : null;
            $currentIndex = $currentStage?->orderIndex() ?? -1;
            $incomingIndex = $stage->orderIndex();

            if ($incomingIndex < $currentIndex) {
                return true;
            }

            $locked->forceFill([
                'processing_stage' => $stage->value,
                'progress' => max((int) $locked->progress, $progress),
            ])->save();

            return true;
        });
    }

    public function markCompleted(KaraokeProject $project, string $runId, KaraokeProcessingResult $result): bool
    {
        return DB::transaction(function () use ($project, $runId, $result) {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->runIsActive($locked, $runId)) {
                $this->removeInstrumentalAtPath($result->instrumentalPath);

                return false;
            }

            if ($locked->status !== KaraokeProjectStatus::Processing) {
                $this->removeInstrumentalAtPath($result->instrumentalPath);

                return false;
            }

            $locked->forceFill([
                'status' => KaraokeProjectStatus::Completed,
                'processing_stage' => KaraokeProcessingStage::Completed->value,
                'progress' => 100,
                'instrumental_path' => $result->instrumentalPath,
                'instrumental_mime_type' => $result->instrumentalMimeType,
                'transcript' => $result->transcript,
                'theme' => $result->theme,
                'processing_completed_at' => now(),
                'error_code' => null,
                'error_message' => $result->disclosure,
            ])->save();

            return true;
        });
    }

    public function markFailed(KaraokeProject $project, string $runId, string $errorCode, string $errorMessage, bool $retryable = true): bool
    {
        return DB::transaction(function () use ($project, $runId, $errorCode, $errorMessage, $retryable) {
            $locked = KaraokeProject::query()->whereKey($project->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->runIsActive($locked, $runId)) {
                return false;
            }

            if (! in_array($locked->status, [KaraokeProjectStatus::Queued, KaraokeProjectStatus::Processing], true)) {
                return false;
            }

            $safeCode = $this->sanitizeErrorCode($errorCode);
            $safeMessage = $this->sanitizeErrorMessage($errorMessage);

            if (! $retryable) {
                $safeCode = 'unsupported_audio';
            }

            $locked->forceFill([
                'status' => KaraokeProjectStatus::Failed,
                'processing_stage' => null,
                'progress' => 0,
                'instrumental_path' => null,
                'instrumental_mime_type' => null,
                'transcript' => null,
                'theme' => null,
                'processing_failed_at' => now(),
                'error_code' => $safeCode,
                'error_message' => $safeMessage,
            ])->save();

            $this->removePartialInstrumental($locked);

            return true;
        });
    }

    public function markJobFailed(KaraokeProject $project, string $runId, Throwable $exception): bool
    {
        return $this->markFailed(
            $project,
            $runId,
            'processing_failed',
            'Processing could not be completed. Please try again.',
            retryable: true,
        );
    }

    public function isRetryable(KaraokeProject $project): bool
    {
        if ($project->status !== KaraokeProjectStatus::Failed) {
            return false;
        }

        return ! in_array($project->error_code, ['unsupported_audio', 'cancelled'], true);
    }

    public function runIsActive(KaraokeProject $project, string $runId): bool
    {
        if ($project->processing_run_id !== $runId) {
            return false;
        }

        if ($project->status === KaraokeProjectStatus::Cancelled) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(KaraokeProject $project): array
    {
        $capabilities = [
            'can_process' => $project->status === KaraokeProjectStatus::Uploaded || $project->status === KaraokeProjectStatus::Cancelled,
            'can_cancel' => in_array($project->status, [KaraokeProjectStatus::Queued, KaraokeProjectStatus::Processing], true),
            'can_retry' => $project->status === KaraokeProjectStatus::Failed && $this->isRetryable($project),
            'can_play' => $project->isReadyForPlayback(),
            'can_edit' => $project->isReadyForEditing(),
        ];

        return [
            'status' => $project->status->value,
            'stage' => $project->processing_stage,
            'stage_label' => $project->processing_stage
                ? KaraokeProcessingStage::tryFrom($project->processing_stage)?->label()
                : null,
            'progress' => (int) $project->progress,
            'retryable' => $this->isRetryable($project),
            'error_code' => $project->error_code,
            'error_message' => $project->error_message,
            'updated_at' => $project->updated_at?->toIso8601String(),
            'capabilities' => $capabilities,
            'routes' => [
                'process' => route('karaoke.projects.process', $project),
                'cancel' => route('karaoke.projects.cancel', $project),
                'retry' => route('karaoke.projects.retry', $project),
                'status' => route('karaoke.projects.status', $project),
            ],
        ];
    }

    public function removePartialInstrumental(KaraokeProject $project): void
    {
        if ($project->instrumental_path) {
            $this->removeInstrumentalAtPath($project->instrumental_path);
        }

        $disk = KaraokeStorage::disk();
        $directory = $project->storageDirectory();

        foreach ($disk->files($directory) as $file) {
            if (str_starts_with(basename($file), 'instrumental.')) {
                $disk->delete($file);
            }
        }
    }

    private function removeInstrumentalAtPath(?string $path): void
    {
        if ($path) {
            KaraokeStorage::disk()->delete($path);
        }
    }

    private function sanitizeErrorCode(string $code): string
    {
        $normalized = strtolower(trim($code));

        if (in_array($normalized, self::SAFE_ERROR_CODES, true)) {
            return $normalized;
        }

        return 'processing_failed';
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $trimmed = trim($message);

        if ($trimmed === '' || str_contains($trimmed, '/') || str_contains($trimmed, '\\')) {
            return 'Processing could not be completed. Please try again.';
        }

        if (strlen($trimmed) > 500) {
            return substr($trimmed, 0, 500);
        }

        return $trimmed;
    }
}
