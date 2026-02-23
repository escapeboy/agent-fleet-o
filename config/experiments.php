<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Execution Time-To-Live (minutes)
    |--------------------------------------------------------------------------
    |
    | Maximum wall-clock time an experiment may run before being automatically
    | killed. Per-experiment overrides via constraints.max_ttl_minutes.
    |
    */
    'default_ttl_minutes' => (int) env('EXPERIMENT_TTL_MINUTES', 120),

    /*
    |--------------------------------------------------------------------------
    | Maximum Execution Depth
    |--------------------------------------------------------------------------
    |
    | Maximum number of completed stages/steps before an experiment is killed.
    | Prevents runaway loops. Per-experiment overrides via constraints.max_execution_depth.
    |
    */
    'default_max_depth' => (int) env('EXPERIMENT_MAX_DEPTH', 50),

    /*
    |--------------------------------------------------------------------------
    | Maximum Concurrent Executions per Agent
    |--------------------------------------------------------------------------
    |
    | Maximum number of experiments that may run simultaneously for the same agent.
    | When exceeded, new jobs are delayed (not killed). Per-experiment overrides
    | via constraints.max_concurrent_executions.
    |
    */
    'default_max_concurrent' => (int) env('EXPERIMENT_MAX_CONCURRENT', 5),

    /*
    |--------------------------------------------------------------------------
    | Stalled Experiment Recovery
    |--------------------------------------------------------------------------
    |
    | Configuration for detecting and recovering experiments stuck in
    | processing states with no active jobs. Timeouts are in seconds.
    |
    */
    'recovery' => [
        'enabled' => env('EXPERIMENT_RECOVERY_ENABLED', true),

        'timeouts' => [
            'scoring' => 300,            // 5 minutes
            'planning' => 600,           // 10 minutes
            'building' => 900,           // 15 minutes
            'awaiting_approval' => 259200, // 72 hours
            'executing' => 1800,         // 30 minutes
            'collecting_metrics' => 900, // 15 minutes
            'evaluating' => 600,         // 10 minutes
        ],

        'max_recovery_attempts' => 4,
        'notify_after_attempts' => 3,
        'pause_after_attempts' => 4,
    ],

];
