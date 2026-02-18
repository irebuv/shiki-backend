<?php

return [
    'limit' => [
        'default' => (int) env('SIMILAR_LIMIT_DEFAULT', 12),
        'min' => (int) env('SIMILAR_LIMIT_MIN', 4),
        'max' => (int) env('SIMILAR_LIMIT_MAX', 24),
        'step' => (int) env('SIMILAR_LIMIT_STEP', 4),
    ],
    'rebuild' => [
        'chunk_default' => (int) env('SIMILAR_REBUILD_CHUNK', 200),
        'delay_step_seconds' => (int) env('SIMILAR_REBUILD_DELAY_STEP', 5),
    ],
];
