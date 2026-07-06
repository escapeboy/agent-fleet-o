<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Agent Loop Detection
    |--------------------------------------------------------------------------
    |
    | Proactive runaway-agent guardrail. Inspects every recorded AiRun turn and
    | trips a circuit breaker BEFORE budget is exhausted when it detects a
    | semantic prompt loop, runaway velocity, or a stalled (repeating) output.
    | Dark-shipped: OFF by default. Cloud/autonomous hosts tighten via env.
    |
    */

    'enabled' => env('LOOP_DETECTION_ENABLED', false),

    // What to do when a loop is detected: 'pause' | 'kill' | 'alert' (observe only).
    'on_trip' => env('LOOP_DETECTION_ON_TRIP', 'pause'),

    // How many recent turns to retain per execution context.
    'window' => (int) env('LOOP_DETECTION_WINDOW', 8),

    // TTL (seconds) for a context's turn history in Redis.
    'ttl_seconds' => (int) env('LOOP_DETECTION_TTL', 1800),

    // Semantic loop: N consecutive prompts whose Bi-Gram Jaccard similarity
    // is at or above the threshold trips a SemanticLoop signal.
    'similarity' => [
        'threshold' => (float) env('LOOP_DETECTION_SIMILARITY', 0.9),
        'min_turns' => (int) env('LOOP_DETECTION_SIMILARITY_MIN_TURNS', 3),
    ],

    // Runaway: more than max_turns within window_seconds trips a Runaway signal.
    'velocity' => [
        'max_turns' => (int) env('LOOP_DETECTION_VELOCITY_MAX', 30),
        'window_seconds' => (int) env('LOOP_DETECTION_VELOCITY_WINDOW', 60),
    ],

    // Stall: identical output repeated repeat_threshold times trips a Stall signal.
    'stall' => [
        'repeat_threshold' => (int) env('LOOP_DETECTION_STALL_REPEATS', 3),
    ],
];
