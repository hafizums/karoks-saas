<?php

namespace Database\Factories;

use App\Models\KaraokeProject;
use App\Models\KaraokeUsageRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KaraokeUsageRecord>
 */
class KaraokeUsageRecordFactory extends Factory
{
    protected $model = KaraokeUsageRecord::class;

    public function definition(): array
    {
        $periodStart = now('UTC')->startOfMonth();
        $periodEnd = $periodStart->copy()->addMonth();

        return [
            'public_id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'karaoke_project_id' => KaraokeProject::factory(),
            'metric' => config('karoks.usage.metric'),
            'units' => 1,
            'state' => KaraokeUsageRecord::STATE_RESERVED,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'idempotency_key' => (string) Str::uuid(),
            'reserved_at' => now(),
        ];
    }
}
