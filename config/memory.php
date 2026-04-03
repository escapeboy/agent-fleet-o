<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embedding Model
    |--------------------------------------------------------------------------
    |
    | The model used to generate embeddings for agent memory storage
    | and retrieval. text-embedding-3-small is the most cost-effective.
    |
    */
    'embedding_provider' => env('MEMORY_EMBEDDING_PROVIDER', 'openai'),

    'embedding_model' => env('MEMORY_EMBEDDING_MODEL', 'text-embedding-3-small'),

    /*
    |--------------------------------------------------------------------------
    | Embedding Dimensions
    |--------------------------------------------------------------------------
    |
    | Number of dimensions for the embedding vectors. Must match the
    | vector column size in the memories table migration.
    |
    */
    'embedding_dimensions' => 1536,

    /*
    |--------------------------------------------------------------------------
    | Memory TTL (Days)
    |--------------------------------------------------------------------------
    |
    | How many days to keep memories before pruning. Set to 0 to disable.
    |
    */
    'ttl_days' => (int) env('MEMORY_TTL_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Top-K Retrieval
    |--------------------------------------------------------------------------
    |
    | Number of most relevant memories to retrieve for context injection.
    |
    */
    'top_k' => (int) env('MEMORY_TOP_K', 5),

    /*
    |--------------------------------------------------------------------------
    | Similarity Threshold
    |--------------------------------------------------------------------------
    |
    | Minimum cosine similarity score (0-1) for memories to be included.
    | Higher values mean stricter matching.
    |
    */
    'similarity_threshold' => (float) env('MEMORY_SIMILARITY_THRESHOLD', 0.7),

    /*
    |--------------------------------------------------------------------------
    | Max Chunk Size
    |--------------------------------------------------------------------------
    |
    | Maximum character count per memory chunk when storing execution outputs.
    |
    */
    'max_chunk_size' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for the memory system. When disabled, no memories
    | are stored or retrieved during agent execution.
    |
    */
    'enabled' => (bool) env('MEMORY_ENABLED', true),

    'auto_flush_enabled' => (bool) env('MEMORY_AUTO_FLUSH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Composite Scoring Weights
    |--------------------------------------------------------------------------
    |
    | Weights for the composite memory scoring formula:
    | score = semantic_weight * similarity + recency_weight * decay + importance_weight * importance
    | Weights should sum to 1.0 for normalized scoring.
    |
    */
    'scoring' => [
        'semantic_weight' => (float) env('MEMORY_SEMANTIC_WEIGHT', 0.5),
        'recency_weight' => (float) env('MEMORY_RECENCY_WEIGHT', 0.3),
        'importance_weight' => (float) env('MEMORY_IMPORTANCE_WEIGHT', 0.2),
        'half_life_days' => (int) env('MEMORY_HALF_LIFE_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Write Gate (Semantic Deduplication)
    |--------------------------------------------------------------------------
    |
    | Before storing a new memory, check for near-duplicates using hash and
    | vector similarity. skip_threshold: exact semantic match (discard).
    | update_threshold: similar enough to merge (LLM-assisted).
    |
    */
    'write_gate' => [
        'enabled' => (bool) env('MEMORY_WRITE_GATE_ENABLED', true),
        'skip_threshold' => (float) env('MEMORY_SKIP_THRESHOLD', 0.95),
        'update_threshold' => (float) env('MEMORY_UPDATE_THRESHOLD', 0.85),
        'hash_dedup' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory Consolidation
    |--------------------------------------------------------------------------
    |
    | Periodic merging of similar memories into consolidated summaries.
    | Reduces noise while preserving signal. Runs as a daily batch job.
    |
    */
    'consolidation' => [
        'enabled' => (bool) env('MEMORY_CONSOLIDATION_ENABLED', true),
        'min_memories_per_agent' => 50,
        'min_cluster_size' => 3,
        'similarity_threshold' => 0.85,
        'exclude_newer_than_days' => 7,
        'model' => 'claude-haiku-4-5',
    ],

    /*
    |--------------------------------------------------------------------------
    | Importance-Weighted Pruning
    |--------------------------------------------------------------------------
    |
    | Pruning considers both age and importance instead of pure TTL.
    | High-importance or frequently-retrieved memories are protected.
    |
    */
    'pruning' => [
        'score_threshold' => (float) env('MEMORY_PRUNE_SCORE_THRESHOLD', 0.05),
        'max_ttl_days' => (int) env('MEMORY_MAX_TTL_DAYS', 365),
        'protect_importance_above' => 0.8,
        'protect_retrieval_above' => 10,
        // Cap on total memories per agent — lowest-scoring are evicted when exceeded
        'max_per_agent' => (int) env('MEMORY_MAX_PER_AGENT', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Unified Search (RRF Fusion)
    |--------------------------------------------------------------------------
    |
    | Reciprocal Rank Fusion across vector memory, knowledge graph, and keyword
    | search. Higher weights = more influence on final ranking.
    |
    */
    'unified_search' => [
        'enabled' => (bool) env('MEMORY_UNIFIED_SEARCH', true),
        'kg_weight' => 2.0,
        'vector_weight' => 1.0,
        'keyword_weight' => 0.5,
        'rrf_k' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Visibility & Cross-Agent Sharing
    |--------------------------------------------------------------------------
    |
    | Controls when private memories are auto-promoted to project scope.
    | A memory needs both minimum retrievals AND importance to be shared.
    |
    */
    'visibility' => [
        'auto_promote_retrievals' => 3,
        'auto_promote_min_importance' => 0.7,
    ],

];
