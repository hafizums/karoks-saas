<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wave\Plan;

class SyncKaroksPlanLimitsCommand extends Command
{
    protected $signature = 'karoks:sync-plan-limits {--dry-run : Report changes without writing to the database}';

    protected $description = 'Merge karoks_processing_jobs_monthly into Wave plan limits without modifying unrelated data';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limitKey = (string) config('karoks.usage.plan_limit_key', 'karoks_processing_jobs_monthly');
        $configuredLimits = config('karoks.usage.plan_limits', []);
        $nameMap = config('karoks.usage.plan_name_map', []);

        $updated = [];
        $unchanged = [];
        $unmatched = [];

        DB::transaction(function () use ($dryRun, $limitKey, $configuredLimits, $nameMap, &$updated, &$unchanged, &$unmatched) {
            $plans = Plan::query()->get();

            foreach ($configuredLimits as $slug => $limitValue) {
                $expectedName = $nameMap[$slug] ?? ucfirst($slug);
                $matchingPlans = $plans->filter(
                    fn (Plan $candidate) => strcasecmp((string) $candidate->name, (string) $expectedName) === 0
                );

                if ($matchingPlans->isEmpty()) {
                    $unmatched[] = $expectedName;

                    continue;
                }

                foreach ($matchingPlans as $plan) {
                    $limits = is_array($plan->limits) ? $plan->limits : [];
                    $current = $limits[$limitKey] ?? null;

                    if ((int) $current === (int) $limitValue) {
                        $unchanged[] = $plan->name;

                        continue;
                    }

                    $limits[$limitKey] = (int) $limitValue;

                    if (! $dryRun) {
                        $plan->forceFill(['limits' => $limits])->save();
                    }

                    $updated[] = $plan->name;
                }
            }

            if (! $dryRun) {
                Plan::clearCache();
            }
        });

        if ($dryRun) {
            $this->info('Dry run — no database changes were made.');
        }

        $this->table(['Result', 'Plans'], [
            ['Updated', implode(', ', $updated) ?: '—'],
            ['Unchanged', implode(', ', $unchanged) ?: '—'],
            ['Unmatched', implode(', ', $unmatched) ?: '—'],
        ]);

        return self::SUCCESS;
    }
}
