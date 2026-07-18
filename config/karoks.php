<?php

return [
    'processing' => [
        'driver' => env('KAROKS_PROCESSING_DRIVER', 'mock'),
        'mock_stage_delay_ms' => (int) env('KAROKS_MOCK_STAGE_DELAY_MS', 0),
    ],
];
