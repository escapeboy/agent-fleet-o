<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Complexity Classification Thresholds
    |--------------------------------------------------------------------------
    |
    | Heuristic thresholds for classifying AI requests as light/standard/heavy.
    | Adjust these to tune when the system upgrades to a more capable model.
    |
    */
    'complexity_thresholds' => [
        'tool_count' => [
            'standard' => 3,
            'heavy' => 11,
        ],
        'max_tokens' => [
            'standard' => 1024,
            'heavy' => 4096,
        ],
        'prompt_tokens' => [
            'standard' => 2000,
            'heavy' => 8000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Budget Pressure Thresholds
    |--------------------------------------------------------------------------
    |
    | Percentage of monthly budget consumed before triggering downgrades.
    | At 'low', heavy requests downgrade to standard.
    | At 'medium', standard also downgrades to light where safe.
    | At 'high', all requests route to the cheapest model.
    |
    */
    'budget_pressure' => [
        'enabled' => (bool) env('AI_BUDGET_PRESSURE_ENABLED', true),
        'thresholds' => [
            'low' => 50,
            'medium' => 75,
            'high' => 90,
        ],
        // Requests with this many tools are never downgraded below standard
        'min_tools_for_standard' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Escalation
    |--------------------------------------------------------------------------
    |
    | When an AI call fails due to quality issues, retry with a stronger model
    | before falling back to another provider. Max escalation attempts limits
    | how many tiers up we try (light→standard→heavy = 2 max).
    |
    */
    'escalation' => [
        'enabled' => (bool) env('AI_ESCALATION_ENABLED', true),
        'max_attempts' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Gate
    |--------------------------------------------------------------------------
    |
    | After each experiment pipeline stage, run mechanical verification on the
    | output. On failure, inject error context and retry within the same job.
    |
    */
    'verification' => [
        'enabled' => (bool) env('AI_VERIFICATION_ENABLED', true),
        'max_retries' => 2,
        'timeout_warning_seconds' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stuck Detection
    |--------------------------------------------------------------------------
    |
    | Sliding-window pattern analysis over recent state transitions to detect
    | loops, oscillations, and stalls. Runs via RecoverStuckTasks command.
    |
    */
    'stuck_detection' => [
        'enabled' => (bool) env('AI_STUCK_DETECTION_ENABLED', true),
        'window_size' => 10,
        'oscillation_threshold' => 3,
        'repeated_failure_threshold' => 3,
        'tool_loop_repetition_rate' => 0.70,
        'stall_multiplier' => 2.0,
        'budget_drain_multiplier' => 3.0,
    ],

];
