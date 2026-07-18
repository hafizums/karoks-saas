<?php

namespace Tests\Support;

use App\Contracts\KaraokeProcessor;
use App\Models\KaraokeProject;
use App\Support\KaraokeProcessingProgress;
use App\Support\KaraokeProcessingResult;
use Closure;
use RuntimeException;

class NeverCalledKaraokeProcessor implements KaraokeProcessor
{
    /**
     * @param  Closure(KaraokeProcessingProgress): void  $reportProgress
     */
    public function process(KaraokeProject $project, string $processingRunId, Closure $reportProgress): KaraokeProcessingResult
    {
        throw new RuntimeException('Processor should not have been invoked.');
    }
}
