<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Skill Quality Thresholds
    |--------------------------------------------------------------------------
    | Thresholds used by MonitorSkillDegradationCommand to detect degraded skills.
    | Skills below these rates will automatically receive EvolutionProposal records.
    */
    'degradation' => [
        'reliability_threshold' => (float) env('SKILLS_RELIABILITY_THRESHOLD', 0.6),
        'quality_threshold' => (float) env('SKILLS_QUALITY_THRESHOLD', 0.5),
        'min_sample_size' => (int) env('SKILLS_MIN_SAMPLE_SIZE', 10),
        'notification_threshold' => (float) env('SKILLS_NOTIFICATION_THRESHOLD', 0.4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Autonomous Evolution
    |--------------------------------------------------------------------------
    | Controls whether the platform auto-generates EvolutionProposals by
    | analyzing completed AgentExecution records via LLM.
    */
    'autonomous_evolution' => [
        'enabled' => (bool) env('SKILLS_AUTO_EVOLVE', true),
        'model' => env('SKILLS_EVOLVE_MODEL', 'claude-haiku-4-5-20251001'),
        'provider' => env('SKILLS_EVOLVE_PROVIDER', 'anthropic'),
        'min_confidence' => (float) env('SKILLS_EVOLVE_MIN_CONFIDENCE', 0.6),
        'max_proposals_per_execution' => (int) env('SKILLS_EVOLVE_MAX_PROPOSALS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hybrid Skill Retrieval
    |--------------------------------------------------------------------------
    | Controls the hybrid BM25 + pgvector skill retrieval at agent runtime.
    */
    'hybrid_retrieval' => [
        'enabled' => (bool) env('SKILLS_HYBRID_RETRIEVAL', true),
        'max_injected' => (int) env('SKILLS_MAX_INJECTED', 2),
        'bm25_weight' => (float) env('SKILLS_BM25_WEIGHT', 0.4),
        'semantic_weight' => (float) env('SKILLS_SEMANTIC_WEIGHT', 0.6),
        'semantic_threshold' => (float) env('SKILLS_SEMANTIC_THRESHOLD', 0.65),
        'embedding_model' => env('SKILLS_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_provider' => env('SKILLS_EMBEDDING_PROVIDER', 'openai'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Propose Skills from Experiments
    |--------------------------------------------------------------------------
    | When an experiment completes successfully with enough stages, the platform
    | auto-proposes a new draft skill encoding the procedure. Inspired by
    | Hermes Agent's auto-skill creation after complex tasks.
    */
    'auto_propose' => [
        'enabled' => (bool) env('SKILLS_AUTO_PROPOSE', true),
        'min_stages' => (int) env('SKILLS_AUTO_PROPOSE_MIN_STAGES', 5),
        'similarity_threshold' => (float) env('SKILLS_AUTO_PROPOSE_SIMILARITY', 0.85),
        'daily_cap' => (int) env('SKILLS_AUTO_PROPOSE_DAILY_CAP', 5),
    ],
];
