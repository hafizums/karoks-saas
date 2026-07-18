<?php

namespace App\Support;

use App\Enums\KaraokeProcessingStage;

readonly class KaraokeProcessingProgress
{
    public function __construct(
        public KaraokeProcessingStage $stage,
        public int $progress,
    ) {}
}
