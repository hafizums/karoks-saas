@php
    $usageLabel = function (?int $value): string {
        if ($value === null) {
            return 'Unlimited';
        }

        return (string) $value;
    };
@endphp

<div class="p-6 space-y-3 border rounded-xl border-zinc-200 dark:border-zinc-700">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Monthly processing usage</h4>
        <p class="text-xs text-zinc-500 dark:text-zinc-400">
            Resets {{ \Illuminate\Support\Carbon::parse($usageSummary['reset_at'])->timezone('UTC')->format('M j, Y') }} (UTC)
        </p>
    </div>

    @if (! $processingEnabled)
        <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-3 dark:bg-amber-950/30 dark:text-amber-100 dark:border-amber-900/60">
            Processing is temporarily unavailable. Existing projects remain accessible, but new processing cannot be started.
        </p>
    @elseif (! $usageSummary['enabled'])
        <p class="text-sm text-red-800 bg-red-50 border border-red-200 rounded-lg p-3 dark:bg-red-950/30 dark:text-red-100 dark:border-red-900/60">
            Processing is disabled for your current plan.
        </p>
    @elseif (! $usageSummary['unlimited'] && ($usageSummary['remaining'] ?? 0) === 0)
        <p class="text-sm text-red-800 bg-red-50 border border-red-200 rounded-lg p-3 dark:bg-red-950/30 dark:text-red-100 dark:border-red-900/60">
            Your monthly processing allowance has been reached.
            @if (\Illuminate\Support\Facades\Route::has('pricing'))
                <a href="{{ route('pricing') }}" wire:navigate class="font-medium underline underline-offset-2">View plans</a>
            @endif
        </p>
    @endif

    <dl class="grid gap-4 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-zinc-500 dark:text-zinc-400">Used</dt>
            <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ $usageSummary['used'] }}</dd>
        </div>
        <div>
            <dt class="text-zinc-500 dark:text-zinc-400">Reserved</dt>
            <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ $usageSummary['reserved'] }}</dd>
        </div>
        <div>
            <dt class="text-zinc-500 dark:text-zinc-400">Remaining</dt>
            <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ $usageLabel($usageSummary['remaining'] ?? null) }}</dd>
        </div>
        <div>
            <dt class="text-zinc-500 dark:text-zinc-400">Monthly limit</dt>
            <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ $usageLabel($usageSummary['limit'] ?? null) }}</dd>
        </div>
    </dl>
</div>
