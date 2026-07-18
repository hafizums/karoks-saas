<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Karaoke Processing
    |--------------------------------------------------------------------------
    |
    | Phase 4 mock processing settings. When processing.enabled is false,
    | new starts and retries are rejected safely while in-flight jobs may finish.
    |
    */
    'processing' => [
        'enabled' => filter_var(env('KAROKS_PROCESSING_ENABLED', true), FILTER_VALIDATE_BOOL),
        'driver' => env('KAROKS_PROCESSING_DRIVER', 'mock'),
        'mock_stage_delay_ms' => (int) env('KAROKS_MOCK_STAGE_DELAY_MS', 0),
        'overlap_release_after_seconds' => (int) env('KAROKS_PROCESSING_OVERLAP_RELEASE_SECONDS', 5),
        'overlap_expire_after_seconds' => (int) env('KAROKS_PROCESSING_OVERLAP_EXPIRE_SECONDS', 360),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monthly Processing Usage (Phase 5)
    |--------------------------------------------------------------------------
    |
    | Allowances are resolved from the active Wave plan limit key
    | `karoks_processing_jobs_monthly` in plans.limits JSON. When the key is
    | missing, invalid, or the user has no active subscription, the default
    | monthly limit below is used (never unlimited).
    |
    | Periods use UTC calendar months:
    | - period_start: first instant of the UTC month
    | - period_end: first instant of the following UTC month
    |
    | Limit semantics for karoks_processing_jobs_monthly:
    | - -1: unlimited
    | - 0: processing disabled for that plan
    | - positive integer: monthly allowance
    | - missing/invalid: default_monthly_limit
    |
    */
    'usage' => [
        'metric' => 'karoks_processing_jobs_monthly',
        'default_monthly_limit' => (int) env('KAROKS_DEFAULT_MONTHLY_PROCESSING_LIMIT', 2),
        'admin_bypass' => filter_var(env('KAROKS_USAGE_ADMIN_BYPASS', true), FILTER_VALIDATE_BOOL),
        'plan_limit_key' => 'karoks_processing_jobs_monthly',
        'plan_limits' => [
            'basic' => 5,
            'premium' => 20,
            'pro' => 100,
        ],
        'plan_name_map' => [
            'basic' => 'Basic',
            'premium' => 'Premium',
            'pro' => 'Pro',
        ],
    ],
];
