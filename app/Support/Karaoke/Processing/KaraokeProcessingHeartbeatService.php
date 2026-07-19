<?php

namespace App\Support\Karaoke\Processing;

use App\Enums\KaraokeProjectStatus;
use App\Models\KaraokeProject;

class KaraokeProcessingHeartbeatService
{
    public function touch(KaraokeProject $project, string $runId): bool
    {
        if ($project->processing_run_id !== $runId) {
            return false;
        }

        if (! in_array($project->status, [KaraokeProjectStatus::Queued, KaraokeProjectStatus::Processing], true)) {
            return false;
        }

        $project->forceFill(['processing_heartbeat_at' => now()])->save();

        return true;
    }

    public function touchLocked(KaraokeProject $project, string $runId): bool
    {
        if ($project->processing_run_id !== $runId) {
            return false;
        }

        if (! in_array($project->status, [KaraokeProjectStatus::Queued, KaraokeProjectStatus::Processing], true)) {
            return false;
        }

        $project->forceFill(['processing_heartbeat_at' => now()]);

        return true;
    }

    public function clearLocked(KaraokeProject $project): void
    {
        $project->forceFill(['processing_heartbeat_at' => null]);
    }
}
