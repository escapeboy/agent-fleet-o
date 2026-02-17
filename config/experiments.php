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

];
