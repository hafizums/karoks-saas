<?php

return [
    'processing' => [
        'driver' => env('KAROKS_PROCESSING_DRIVER', 'mock'),
        'mock_stage_delay_ms' => (int) env('KAROKS_MOCK_STAGE_DELAY_MS', 0),
        'overlap_release_after_seconds' => (int) env('KAROKS_PROCESSING_OVERLAP_RELEASE_SECONDS', 5),
        'overlap_expire_after_seconds' => (int) env('KAROKS_PROCESSING_OVERLAP_EXPIRE_SECONDS', 360),
    ],
];
