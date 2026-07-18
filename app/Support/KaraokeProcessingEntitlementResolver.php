<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Wave\Plan;
use Wave\Subscription;

class KaraokeProcessingEntitlementResolver
{
    /**
     * Resolve the monthly processing limit for a user.
     *
     * @return array{limit: int|null, unlimited: bool, disabled: bool}
     */
    public function resolve(User $user): array
    {
        if ($this->adminBypassApplies($user)) {
            return [
                'limit' => null,
                'unlimited' => true,
                'disabled' => false,
            ];
        }

        $rawLimit = $this->resolveRawPlanLimit($user);

        if ($rawLimit === null) {
            $default = (int) config('karoks.usage.default_monthly_limit', 2);

            return [
                'limit' => max(0, $default),
                'unlimited' => false,
                'disabled' => $default === 0,
            ];
        }

        if ($rawLimit === -1) {
            return [
                'limit' => null,
                'unlimited' => true,
                'disabled' => false,
            ];
        }

        if ($rawLimit === 0) {
            return [
                'limit' => 0,
                'unlimited' => false,
                'disabled' => true,
            ];
        }

        if ($rawLimit > 0) {
            return [
                'limit' => $rawLimit,
                'unlimited' => false,
                'disabled' => false,
            ];
        }

        Log::warning('Invalid karoks_processing_jobs_monthly limit value encountered.', [
            'user_id' => $user->id,
            'value' => $rawLimit,
        ]);

        $default = (int) config('karoks.usage.default_monthly_limit', 2);

        return [
            'limit' => max(0, $default),
            'unlimited' => false,
            'disabled' => $default === 0,
        ];
    }

    public function adminBypassApplies(User $user): bool
    {
        if (! config('karoks.usage.admin_bypass', true)) {
            return false;
        }

        return $user->isAdmin();
    }

    private function resolveRawPlanLimit(User $user): ?int
    {
        $subscription = $this->latestActiveSubscription($user);

        if ($subscription === null) {
            return null;
        }

        $plan = Plan::query()->find($subscription->plan_id);

        if ($plan === null) {
            return null;
        }

        $limits = $plan->limits ?? [];
        $key = (string) config('karoks.usage.plan_limit_key', 'karoks_processing_jobs_monthly');

        if (! array_key_exists($key, $limits)) {
            return null;
        }

        $value = $limits[$key];

        if (! is_int($value) && ! (is_string($value) && ctype_digit((string) $value))) {
            if (! is_numeric($value)) {
                return null;
            }
        }

        return (int) $value;
    }

    private function latestActiveSubscription(User $user): ?Subscription
    {
        return Subscription::query()
            ->where('billable_type', 'user')
            ->where('billable_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->first();
    }
}
