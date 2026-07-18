<?php

namespace App\Jobs;

use App\Contracts\KaraokeProcessor;
use App\Exceptions\KaraokeProcessingException;
use App\Exceptions\NonRetryableKaraokeProcessingException;
use App\Exceptions\ProcessingRunInterruptedException;
use App\Models\KaraokeProject;
use App\Support\KaraokeProcessingStateService;
use App\Support\KaraokeStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessKaraokeProject implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function __construct(
        public int $karaokeProjectId,
        public string $processingRunId,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $releaseAfter = max(1, (int) config('karoks.processing.overlap_release_after_seconds', 5));
        $expireAfter = max($this->timeout + 60, (int) config('karoks.processing.overlap_expire_after_seconds', 360));

        return [
            (new WithoutOverlapping('karaoke-project:'.$this->karaokeProjectId))
                ->releaseAfter($releaseAfter)
                ->expireAfter($expireAfter),
        ];
    }

    public function handle(
        KaraokeProcessor $processor,
        KaraokeProcessingStateService $stateService,
    ): void {
        $project = KaraokeProject::query()->find($this->karaokeProjectId);

        if ($project === null) {
            return;
        }

        if (! $stateService->runIsActive($project, $this->processingRunId)) {
            return;
        }

        if (! $stateService->beginProcessingRun($project, $this->processingRunId)) {
            return;
        }

        $project->refresh();

        try {
            $result = $processor->process(
                $project,
                $this->processingRunId,
                function ($progress) use ($stateService, $project): void {
                    $fresh = KaraokeProject::query()->find($project->id);

                    if ($fresh === null || ! $stateService->runIsActive($fresh, $this->processingRunId)) {
                        throw new ProcessingRunInterruptedException();
                    }

                    $stateService->recordProgress(
                        $fresh,
                        $this->processingRunId,
                        $progress->stage,
                        $progress->progress,
                    );
                },
            );

            $fresh = KaraokeProject::query()->find($project->id);

            if ($fresh === null) {
                KaraokeStorage::disk()->delete($result->instrumentalPath);

                return;
            }

            if (! $stateService->markCompleted($fresh, $this->processingRunId, $result)) {
                KaraokeStorage::disk()->delete($result->instrumentalPath);
            }
        } catch (ProcessingRunInterruptedException) {
            return;
        } catch (NonRetryableKaraokeProcessingException $exception) {
            $fresh = KaraokeProject::query()->find($project->id);

            if ($fresh !== null) {
                $stateService->markFailed(
                    $fresh,
                    $this->processingRunId,
                    $this->resolveErrorCode($exception),
                    $this->resolveErrorMessage($exception),
                    retryable: false,
                );
            }
        } catch (KaraokeProcessingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->isRetryableThrowable($exception)) {
                throw $exception;
            }

            $fresh = KaraokeProject::query()->find($project->id);

            if ($fresh !== null) {
                $stateService->markFailed(
                    $fresh,
                    $this->processingRunId,
                    'processing_failed',
                    'Processing could not be completed. Please try again.',
                    retryable: false,
                );
            }
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        $project = KaraokeProject::query()->find($this->karaokeProjectId);

        if ($project === null) {
            return;
        }

        app(KaraokeProcessingStateService::class)->markJobFailed(
            $project,
            $this->processingRunId,
            $exception ?? new KaraokeProcessingException('Job failed'),
        );
    }

    private function resolveErrorCode(NonRetryableKaraokeProcessingException $exception): string
    {
        return match ($exception->getMessage()) {
            'source_missing' => 'source_missing',
            'unsupported_audio' => 'unsupported_audio',
            default => 'processing_failed',
        };
    }

    private function resolveErrorMessage(NonRetryableKaraokeProcessingException $exception): string
    {
        return match ($exception->getMessage()) {
            'source_missing' => 'The uploaded source audio could not be found.',
            'unsupported_audio' => 'This audio format is not supported.',
            default => 'Processing could not be completed.',
        };
    }

    private function isRetryableThrowable(Throwable $exception): bool
    {
        return ! $exception instanceof NonRetryableKaraokeProcessingException;
    }
}
