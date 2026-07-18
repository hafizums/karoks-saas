<?php

namespace Tests\Support;

use App\Contracts\KaraokeProcessor;
use App\Jobs\ProcessKaraokeProject;
use App\Models\KaraokeProject;
use App\Support\KaraokeProcessingStateService;

function bindMockProcessingProcessor(KaraokeProcessor $processor): void
{
    app()->instance('karoks.testing.mock_processor', $processor);
}

function runKaraokeProcessingJob(KaraokeProject $project): void
{
    $project->refresh();

    (new ProcessKaraokeProject($project->id, (string) $project->processing_run_id))->handle(
        app(KaraokeProcessingStateService::class),
    );
}
