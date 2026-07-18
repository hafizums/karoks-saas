<?php

namespace Tests\Support;

use App\Contracts\KaraokeProcessor;
use App\Exceptions\KaraokeProcessingException;
use App\Models\KaraokeProject;
use App\Support\Karaoke\Processors\MockKaraokeProcessor;
use App\Support\KaraokeProcessingProgress;
use App\Support\KaraokeProcessingResult;
use Closure;

class FlakyKaraokeProcessor implements KaraokeProcessor
{
    public int $attempts = 0;

    public function __construct(
        private readonly int $failuresBeforeSuccess = 1,
        private readonly MockKaraokeProcessor $delegate = new MockKaraokeProcessor(),
    ) {}

    /**
     * @param  Closure(KaraokeProcessingProgress): void  $reportProgress
     */
    public function process(KaraokeProject $project, string $processingRunId, Closure $reportProgress): KaraokeProcessingResult
    {
        $this->attempts++;

        if ($this->attempts <= $this->failuresBeforeSuccess) {
            throw new KaraokeProcessingException('Transient processing failure.');
        }

        return $this->delegate->process($project, $processingRunId, $reportProgress);
    }
}
