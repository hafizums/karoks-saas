<?php

namespace App\Jobs;

use App\Contracts\KaraokeProcessor;
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
        return [
            (new WithoutOverlapping('karaoke-project:'.$this->karaokeProjectId))->dontRelease(),
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
        } catch (Throwable) {
            $fresh = KaraokeProject::query()->find($project->id);

            if ($fresh !== null) {
                $stateService->markFailed(
                    $fresh,
                    $this->processingRunId,
                    'processing_failed',
                    'Processing could not be completed. Please try again.',
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
            $exception ?? new \RuntimeException('Job failed'),
        );
    }
}
