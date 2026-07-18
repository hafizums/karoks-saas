<?php

namespace App\Contracts;

use App\Models\KaraokeProject;
use App\Support\KaraokeProcessingProgress;
use App\Support\KaraokeProcessingResult;
use Closure;

interface KaraokeProcessor
{
    /**
     * @param  Closure(KaraokeProcessingProgress): void  $reportProgress
     */
    public function process(KaraokeProject $project, string $processingRunId, Closure $reportProgress): KaraokeProcessingResult;
}
