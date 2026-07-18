<?php

namespace App\Support\Karaoke\Processing;

use App\Exceptions\ProcessingRunInterruptedException;
use App\Models\KaraokeProject;
use App\Support\KaraokeProcessingStateService;

class KaraokeProcessingRunGuard
{
    public function __construct(
        private readonly KaraokeProcessingStateService $stateService,
    ) {}

    public function assertActive(KaraokeProject $project, string $runId): void
    {
        $fresh = KaraokeProject::query()->find($project->id);

        if ($fresh === null || ! $this->stateService->runIsActive($fresh, $runId)) {
            throw new ProcessingRunInterruptedException();
        }
    }
}
