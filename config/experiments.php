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
    | Scoring Rubrics (signal-aware experiment scoring)
    |--------------------------------------------------------------------------
    | RunScoringStage picks a rubric — its scoring question (system_prompt) +
    | default threshold — per experiment via this chain:
    |   constraints.score_threshold (per-run override, threshold only)
    |     → scoring_rubric_by_source[signal.source_type]
    |     → scoring_rubrics[experiment.track]
    |     → 'default'
    | Different signal types are judged by the right criteria (business potential
    | vs bug severity). recommended_track / scoring_details are display-only, so a
    | rubric may use its own vocabulary. Add a signal type → add a rubric or a
    | source route here, no code change. A missing 'default' falls back to a
    | built-in business prompt + 0.3 in RunScoringStage.
    */
    'scoring_rubrics' => [
        'default' => [
            'threshold' => 0.3,
            'system_prompt' => 'You are an experiment scoring agent. Evaluate the business potential of the given signal or thesis. If context from predecessor projects is provided, factor it into your evaluation. Return ONLY a valid JSON object (no markdown, no code fences) with: score (0.0-1.0), reasoning (string, max 2 sentences), recommended_track (growth|retention|revenue|engagement), and key_metrics (array of max 5 short strings). Keep the response compact.',
        ],
        'debug' => [
            'threshold' => 0.2,
            'system_prompt' => 'You are a bug-triage scoring agent. Assess the SEVERITY and IMPACT of the reported bug, NOT its business novelty. Weigh reproducibility, blast radius (users/flows affected), user- and revenue-impact, and frequency. A real, reproducible production error scores HIGH (0.7-1.0); a minor or cosmetic issue scores MEDIUM; noise — non-reproducible, third-party, duplicate, or non-actionable — scores LOW (near 0). Return ONLY a valid JSON object (no markdown, no code fences) with: score (0.0-1.0), reasoning (string, max 2 sentences), recommended_track (critical|high|medium|low), and key_metrics (array of max 5 short strings, e.g. affected flow, error frequency). Keep the response compact.',
        ],
    ],

    /*
    | Map a signal's AI-classified source_type to a rubric key. Strongest signal
    | of intent (known at ingestion). Unlisted types fall through to track/default.
    */
    'scoring_rubric_by_source' => [
        'bug_report' => 'debug',
        'incident' => 'debug',
        'alert' => 'debug',
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

    /*
    |--------------------------------------------------------------------------
    | Require team AI access on experiment creation
    |--------------------------------------------------------------------------
    | When enabled, CreateExperimentAction rejects teams that have no usable AI
    | path (no BYOK key, no platform_llm_fallback, no local/bridge agent, and not
    | an internal sub-program) with an actionable message — instead of letting the
    | run fail mid-pipeline. Default off so it can be flipped per environment.
    */
    'require_team_ai_access' => (bool) env('EXPERIMENTS_REQUIRE_TEAM_AI_ACCESS', false),

    /*
    |--------------------------------------------------------------------------
    | Warm build sandbox
    |--------------------------------------------------------------------------
    | When enabled, builds reuse a persistent per-(team, repo) base clone and an
    | isolated worktree per run (WarmRepoManager) instead of re-cloning the repo
    | every time — clone once, then `git fetch` + worktree (seconds vs minutes).
    | base_dir should be a persistent volume in production so the warm clones
    | survive container restarts. Off by default.
    */
    'warm_build' => [
        'enabled' => (bool) env('EXPERIMENTS_WARM_BUILD', false),
        'base_dir' => env('EXPERIMENTS_WARM_BUILD_DIR', storage_path('app/warm-repos')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transient capacity retries
    |--------------------------------------------------------------------------
    | A stage that hits a transient shared-resource limit (the per-team VPS
    | concurrency cap) is re-dispatched after a backoff rather than failed. The
    | budget is tracked per stage so it stays independent of the framework's
    | $tries (which is reserved for genuine failures). max_retries * backoff is
    | the worst-case wait for a slot; the default (~20 min) covers a couple of
    | long-held build slots ahead of a queued burst.
    */
    'transient_capacity' => [
        'max_retries' => (int) env('EXPERIMENTS_TRANSIENT_CAP_MAX_RETRIES', 20),
        'backoff_seconds' => (int) env('EXPERIMENTS_TRANSIENT_CAP_BACKOFF', 60),
    ],

];
