<?php

namespace App\Support;

use App\Exceptions\KaraokeProcessingDisabledException;
use App\Exceptions\KaraokeUsageLimitExceededException;
use App\Models\KaraokeProject;
use App\Models\KaraokeUsageRecord;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class KaraokeUsageService
{
    public function __construct(
        private readonly KaraokeProcessingEntitlementResolver $entitlementResolver,
    ) {}

    public function processingEnabled(): bool
    {
        return (bool) config('karoks.processing.enabled', true);
    }

    public function assertProcessingEnabled(): void
    {
        if (! $this->processingEnabled()) {
            throw new KaraokeProcessingDisabledException();
        }
    }

    /**
     * @return array{start: CarbonInterface, end: CarbonInterface}
     */
    public function currentPeriod(?CarbonInterface $at = null): array
    {
        $moment = Carbon::instance($at ?? now('UTC'))->timezone('UTC');
        $start = $moment->copy()->startOfMonth();
        $end = $start->copy()->addMonth();

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * @return array{
     *     metric: string,
     *     limit: int|null,
     *     used: int,
     *     reserved: int,
     *     remaining: int|null,
     *     unlimited: bool,
     *     reset_at: string,
     *     enabled: bool
     * }
     */
    public function summary(User $user, ?CarbonInterface $at = null): array
    {
        $metric = (string) config('karoks.usage.metric');
        $period = $this->currentPeriod($at);
        $entitlement = $this->entitlementResolver->resolve($user);
        $counts = $this->periodCounts($user, $metric, $period['start'], $period['end']);

        $remaining = null;

        if (! $entitlement['unlimited'] && $entitlement['limit'] !== null) {
            $remaining = max(0, $entitlement['limit'] - $counts['used'] - $counts['reserved']);
        }

        return [
            'metric' => $metric,
            'limit' => $entitlement['unlimited'] ? null : $entitlement['limit'],
            'used' => $counts['used'],
            'reserved' => $counts['reserved'],
            'remaining' => $remaining,
            'unlimited' => $entitlement['unlimited'],
            'reset_at' => $period['end']->toIso8601String(),
            'enabled' => $this->processingEnabled() && ! $entitlement['disabled'],
        ];
    }

    public function canReserve(User $user): bool
    {
        if (! $this->processingEnabled()) {
            return false;
        }

        $summary = $this->summary($user);

        if (! $summary['enabled']) {
            return false;
        }

        if ($summary['unlimited']) {
            return true;
        }

        return ($summary['remaining'] ?? 0) > 0;
    }

    public function reserveForProject(User $user, KaraokeProject $project, int $attemptNumber): KaraokeUsageRecord
    {
        $this->assertProcessingEnabled();

        $metric = (string) config('karoks.usage.metric');
        $period = $this->currentPeriod();

        return DB::transaction(function () use ($user, $project, $attemptNumber, $metric, $period) {
            $existing = $this->lockReservedProjectRecord($project);

            if ($existing !== null) {
                return $existing;
            }

            $this->lockUserForUsage($user);

            $entitlement = $this->entitlementResolver->resolve($user);

            if ($entitlement['disabled']) {
                throw new KaraokeUsageLimitExceededException();
            }

            if (! $entitlement['unlimited']) {
                $counts = $this->periodCounts($user, $metric, $period['start'], $period['end'], lock: true);

                if (($counts['used'] + $counts['reserved']) >= (int) $entitlement['limit']) {
                    throw new KaraokeUsageLimitExceededException();
                }
            }

            $idempotencyKey = $this->reserveIdempotencyKey($project->id, $attemptNumber);

            return $this->createReservationRecord(
                $user,
                $project,
                $metric,
                $period,
                $idempotencyKey,
                $entitlement,
            );
        });
    }

    public function consumeForRun(KaraokeProject $project, string $runId): bool
    {
        return DB::transaction(function () use ($project, $runId) {
            $this->lockUserForUsage($project->user);

            $record = KaraokeUsageRecord::query()
                ->where('karaoke_project_id', $project->id)
                ->whereIn('state', [
                    KaraokeUsageRecord::STATE_RESERVED,
                    KaraokeUsageRecord::STATE_CONSUMED,
                ])
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($record === null) {
                return false;
            }

            if ($record->state === KaraokeUsageRecord::STATE_CONSUMED) {
                return true;
            }

            $consumeKey = $this->consumeIdempotencyKey($runId);

            $duplicateConsume = KaraokeUsageRecord::query()
                ->where('idempotency_key', $consumeKey)
                ->lockForUpdate()
                ->exists();

            if ($duplicateConsume) {
                return true;
            }

            $record->forceFill([
                'state' => KaraokeUsageRecord::STATE_CONSUMED,
                'consumed_at' => now(),
                'idempotency_key' => $consumeKey,
            ])->save();

            return true;
        });
    }

    public function releaseQueuedForProject(KaraokeProject $project, string $reason = 'cancelled'): bool
    {
        return DB::transaction(function () use ($project, $reason) {
            $record = KaraokeUsageRecord::query()
                ->where('karaoke_project_id', $project->id)
                ->where('state', KaraokeUsageRecord::STATE_RESERVED)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($record === null) {
                return false;
            }

            $record->forceFill([
                'state' => KaraokeUsageRecord::STATE_RELEASED,
                'released_at' => now(),
                'release_reason' => $this->sanitizeReleaseReason($reason),
                'idempotency_key' => $this->releaseIdempotencyKey($record->id),
            ])->save();

            return true;
        });
    }

    public function detachProjectRecords(KaraokeProject $project): void
    {
        DB::transaction(function () use ($project) {
            $this->releaseQueuedForProject($project, 'project_deleted');

            KaraokeUsageRecord::query()
                ->where('karaoke_project_id', $project->id)
                ->update(['karaoke_project_id' => null]);
        });
    }

    public function hasActiveProjectUsage(KaraokeProject $project): bool
    {
        return KaraokeUsageRecord::query()
            ->where('karaoke_project_id', $project->id)
            ->whereIn('state', [
                KaraokeUsageRecord::STATE_RESERVED,
                KaraokeUsageRecord::STATE_CONSUMED,
            ])
            ->exists();
    }

    public function hasConsumedProjectUsage(KaraokeProject $project): bool
    {
        return KaraokeUsageRecord::query()
            ->where('karaoke_project_id', $project->id)
            ->where('state', KaraokeUsageRecord::STATE_CONSUMED)
            ->exists();
    }

    /**
     * @return array{used: int, reserved: int}
     */
    private function periodCounts(
        User $user,
        string $metric,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        bool $lock = false,
    ): array {
        $query = KaraokeUsageRecord::query()
            ->where('user_id', $user->id)
            ->where('metric', $metric)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd);

        if ($lock) {
            $query->lockForUpdate();
        }

        $records = $query->get(['state', 'units']);

        $used = 0;
        $reserved = 0;

        foreach ($records as $record) {
            if ($record->state === KaraokeUsageRecord::STATE_CONSUMED) {
                $used += (int) $record->units;
            } elseif ($record->state === KaraokeUsageRecord::STATE_RESERVED) {
                $reserved += (int) $record->units;
            }
        }

        return [
            'used' => $used,
            'reserved' => $reserved,
        ];
    }

    /**
     * @param  array{start: CarbonInterface, end: CarbonInterface}  $period
     * @param  array{limit: int|null, unlimited: bool, disabled: bool}  $entitlement
     */
    private function createReservationRecord(
        User $user,
        KaraokeProject $project,
        string $metric,
        array $period,
        string $idempotencyKey,
        array $entitlement,
    ): KaraokeUsageRecord {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            try {
                return KaraokeUsageRecord::query()->create([
                    'user_id' => $user->id,
                    'karaoke_project_id' => $project->id,
                    'metric' => $metric,
                    'units' => 1,
                    'state' => KaraokeUsageRecord::STATE_RESERVED,
                    'period_start' => $period['start'],
                    'period_end' => $period['end'],
                    'idempotency_key' => $idempotencyKey,
                    'reserved_at' => now(),
                ]);
            } catch (QueryException $exception) {
                if ($this->isUniqueConstraintViolation($exception)) {
                    $existing = $this->lockReservedProjectRecord($project);

                    if ($existing !== null) {
                        return $existing;
                    }

                    throw new KaraokeUsageLimitExceededException();
                }

                if ($this->isDatabaseLocked($exception)) {
                    usleep(100000 * ($attempt + 1));
                    $this->lockUserForUsage($user);
                    $counts = $this->periodCounts($user, $metric, $period['start'], $period['end'], lock: true);

                    if (! $entitlement['unlimited'] && ($counts['used'] + $counts['reserved']) >= (int) $entitlement['limit']) {
                        throw new KaraokeUsageLimitExceededException();
                    }

                    continue;
                }

                throw $exception;
            }
        }

        $this->lockUserForUsage($user);
        $counts = $this->periodCounts($user, $metric, $period['start'], $period['end'], lock: true);

        if (! $entitlement['unlimited'] && ($counts['used'] + $counts['reserved']) >= (int) $entitlement['limit']) {
            throw new KaraokeUsageLimitExceededException();
        }

        throw new KaraokeUsageLimitExceededException();
    }

    private function lockUserForUsage(User $user): User
    {
        $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();

        if ($locked === null) {
            throw new KaraokeUsageLimitExceededException();
        }

        return $locked;
    }

    private function lockReservedProjectRecord(KaraokeProject $project): ?KaraokeUsageRecord
    {
        return KaraokeUsageRecord::query()
            ->where('karaoke_project_id', $project->id)
            ->where('state', KaraokeUsageRecord::STATE_RESERVED)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();
    }

    private function reserveIdempotencyKey(int $projectId, int $attemptNumber): string
    {
        return sprintf('reserve:project:%d:attempt:%d', $projectId, $attemptNumber);
    }

    private function consumeIdempotencyKey(string $runId): string
    {
        return 'consume:run:'.$runId;
    }

    private function releaseIdempotencyKey(int $recordId): string
    {
        return 'released:record:'.$recordId;
    }

    private function sanitizeReleaseReason(string $reason): string
    {
        $normalized = strtolower(trim($reason));
        $allowed = ['cancelled', 'project_deleted', 'superseded'];

        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }

        return 'cancelled';
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return in_array($sqlState, ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }

    private function isDatabaseLocked(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database is busy')
            || str_contains($message, 'locked');
    }
}
