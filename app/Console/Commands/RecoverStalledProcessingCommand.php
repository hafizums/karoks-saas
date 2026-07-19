<?php

namespace App\Console\Commands;

use App\Enums\KaraokeProcessingNotificationEvent;
use App\Enums\KaraokeProjectStatus;
use App\Jobs\ProcessKaraokeProject;
use App\Models\KaraokeProject;
use App\Support\Karaoke\Processing\KaraokeProcessingRecoveryReference;
use App\Support\KaraokeProcessingStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecoverStalledProcessingCommand extends Command
{
    protected $signature = 'karoks:recover-stalled-processing
                            {--dry-run : Report candidates without writing or dispatching}
                            {--queued-minutes= : Queued heartbeat threshold in minutes}
                            {--processing-minutes= : Processing heartbeat threshold in minutes}
                            {--limit= : Maximum projects to recover per category}';

    protected $description = 'Recover stalled queued jobs and fail stalled processing runs safely.';

    public function handle(KaraokeProcessingStateService $stateService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $queuedMinutes = max(1, (int) ($this->option('queued-minutes') ?? config('karoks.processing.recovery_queued_minutes', 10)));
        $processingMinutes = max(1, (int) ($this->option('processing-minutes') ?? config('karoks.processing.recovery_processing_minutes', 15)));
        $limit = max(1, (int) ($this->option('limit') ?? config('karoks.processing.recovery_limit', 50)));

        $queuedThreshold = now()->subMinutes($queuedMinutes);
        $processingThreshold = now()->subMinutes($processingMinutes);

        $queuedRecovered = $this->recoverQueuedProjects($queuedThreshold, $limit, $dryRun);
        $processingRecovered = $this->recoverProcessingProjects($stateService, $processingThreshold, $limit, $dryRun);

        $this->info(sprintf(
            'Recovery complete%s. queued=%d processing=%d',
            $dryRun ? ' (dry-run)' : '',
            $queuedRecovered,
            $processingRecovered,
        ));

        return self::SUCCESS;
    }

    private function recoverQueuedProjects(Carbon $threshold, int $limit, bool $dryRun): int
    {
        $recovered = 0;

        $candidates = KaraokeProject::query()
            ->where('status', KaraokeProjectStatus::Queued)
            ->whereNotNull('processing_run_id')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->filter(fn (KaraokeProject $project): bool => KaraokeProcessingRecoveryReference::isStale($project, $threshold))
            ->pluck('id');

        foreach ($candidates as $projectId) {
            $dispatched = DB::transaction(function () use ($projectId, $threshold, $dryRun): bool {
                $locked = KaraokeProject::query()->whereKey($projectId)->lockForUpdate()->first();

                if ($locked === null) {
                    return false;
                }

                if ($locked->status !== KaraokeProjectStatus::Queued || $locked->processing_run_id === null) {
                    return false;
                }

                if (! KaraokeProcessingRecoveryReference::isStale($locked, $threshold)) {
                    return false;
                }

                if ($dryRun) {
                    return true;
                }

                ProcessKaraokeProject::dispatch($locked->id, (string) $locked->processing_run_id)->afterCommit();
                $locked->forceFill(['processing_heartbeat_at' => now()])->save();

                return true;
            });

            if ($dispatched) {
                $recovered++;
            }
        }

        return $recovered;
    }

    private function recoverProcessingProjects(
        KaraokeProcessingStateService $stateService,
        Carbon $threshold,
        int $limit,
        bool $dryRun,
    ): int {
        $recovered = 0;

        $candidates = KaraokeProject::query()
            ->where('status', KaraokeProjectStatus::Processing)
            ->whereNotNull('processing_run_id')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->filter(fn (KaraokeProject $project): bool => KaraokeProcessingRecoveryReference::isStale($project, $threshold))
            ->pluck('id');

        foreach ($candidates as $projectId) {
            $marked = DB::transaction(function () use ($projectId, $threshold, $dryRun, $stateService): bool {
                $locked = KaraokeProject::query()->whereKey($projectId)->lockForUpdate()->first();

                if ($locked === null) {
                    return false;
                }

                if ($locked->status !== KaraokeProjectStatus::Processing || $locked->processing_run_id === null) {
                    return false;
                }

                if (! KaraokeProcessingRecoveryReference::isStale($locked, $threshold)) {
                    return false;
                }

                if ($dryRun) {
                    return true;
                }

                return $stateService->markFailed(
                    $locked,
                    (string) $locked->processing_run_id,
                    'processing_stalled',
                    'Processing stalled while waiting for the provider. Please try again.',
                    retryable: true,
                    notificationEvent: KaraokeProcessingNotificationEvent::Stalled,
                );
            });

            if ($marked) {
                $recovered++;
            }
        }

        return $recovered;
    }
}
