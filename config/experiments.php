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

    /*
    |--------------------------------------------------------------------------
    | Sub-Experiment Orchestration
    |--------------------------------------------------------------------------
    |
    | Configuration for parent/child experiment orchestration. Controls
    | nesting limits, child count, failure policies, and budget allocation.
    |
    */
    'orchestration' => [
        'max_nesting_depth' => (int) env('EXPERIMENT_MAX_NESTING_DEPTH', 2),
        'max_children' => (int) env('EXPERIMENT_MAX_CHILDREN', 5),
        'default_failure_policy' => env('EXPERIMENT_FAILURE_POLICY', 'continue_on_partial'),
        'budget_allocation_strategy' => env('EXPERIMENT_BUDGET_STRATEGY', 'on_demand'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stage Model Tiers (Smart Model Routing)
    |--------------------------------------------------------------------------
    |
    | Maps each pipeline stage to a cost tier. Stages mapped to 'cheap' use
    | smaller/faster models, 'expensive' uses top-tier models, 'standard'
    | (or null) falls through to the team/platform default.
    |
    */
    'stage_model_tiers' => [
        'scoring' => 'cheap',
        'planning' => 'expensive',
        'building' => 'expensive',
        'awaiting_approval' => null,
        'executing' => 'standard',
        'collecting_metrics' => 'cheap',
        'evaluating' => 'standard',
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Tiers
    |--------------------------------------------------------------------------
    |
    | Concrete provider/model pairs for each tier. 'standard' (null) means
    | use the team default resolved by ProviderResolver. The first provider
    | the team has credentials for wins.
    |
    */
    'model_tiers' => [
        'cheap' => ['anthropic' => 'claude-haiku-4-5', 'openai' => 'gpt-4o-mini', 'google' => 'gemini-2.5-flash'],
        'standard' => null,
        'expensive' => ['anthropic' => 'claude-sonnet-4-5', 'openai' => 'gpt-4o', 'google' => 'gemini-2.5-pro'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline Context Compression
    |--------------------------------------------------------------------------
    | Compresses preceding stage outputs when they exceed the token threshold.
    | Head stages and tail stages are preserved in full; middle stages are
    | pruned and optionally LLM-summarized. Inspired by Hermes Agent.
    */
    'context_compression' => [
        'enabled' => (bool) env('EXPERIMENT_CONTEXT_COMPRESSION', true),
        'threshold_tokens' => (int) env('EXPERIMENT_COMPRESSION_THRESHOLD', 30000),
        'head_stages' => 1,
        'tail_stages' => 2,
    ],

];
