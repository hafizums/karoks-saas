<?php

namespace App\Support\Karaoke\Processing;

use App\Models\KaraokeProject;
use Illuminate\Support\Carbon;

class KaraokeProcessingRecoveryReference
{
    public static function referenceTime(KaraokeProject $project): ?Carbon
    {
        return $project->processing_heartbeat_at
            ?? $project->processing_started_at
            ?? $project->queued_at;
    }

    public static function isStale(KaraokeProject $project, Carbon $threshold): bool
    {
        $reference = self::referenceTime($project);

        return $reference !== null && $reference->lte($threshold);
    }
}
