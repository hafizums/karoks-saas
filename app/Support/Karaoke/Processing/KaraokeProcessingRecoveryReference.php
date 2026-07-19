<?php

namespace App\Support\Karaoke\Processing;

use App\Models\KaraokeProject;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * @param  Builder<KaraokeProject>  $query
     * @return Builder<KaraokeProject>
     */
    public static function applyStaleScope(Builder $query, Carbon $threshold): Builder
    {
        return $query->where(function (Builder $query) use ($threshold): void {
            $query->where(function (Builder $query) use ($threshold): void {
                $query->whereNotNull('processing_heartbeat_at')
                    ->where('processing_heartbeat_at', '<=', $threshold);
            })->orWhere(function (Builder $query) use ($threshold): void {
                $query->whereNull('processing_heartbeat_at')
                    ->whereNotNull('processing_started_at')
                    ->where('processing_started_at', '<=', $threshold);
            })->orWhere(function (Builder $query) use ($threshold): void {
                $query->whereNull('processing_heartbeat_at')
                    ->whereNull('processing_started_at')
                    ->whereNotNull('queued_at')
                    ->where('queued_at', '<=', $threshold);
            });
        });
    }
}
