<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Semantic Cache Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for semantic response caching. When enabled, the
    | SemanticCache middleware will intercept LLM calls and return cached
    | responses for semantically equivalent prompts.
    |
    */
    'enabled' => (bool) env('SEMANTIC_CACHE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Embedding Model
    |--------------------------------------------------------------------------
    |
    | The model used to generate prompt embeddings for similarity matching.
    | text-embedding-3-small provides a good cost/quality tradeoff.
    |
    */
    'embedding_model' => env('SEMANTIC_CACHE_EMBEDDING_MODEL', 'text-embedding-3-small'),

    /*
    |--------------------------------------------------------------------------
    | Similarity Threshold
    |--------------------------------------------------------------------------
    |
    | Minimum cosine similarity (0–1) required to serve a cached response.
    | Higher values mean stricter matching. 0.92 catches paraphrases while
    | avoiding false positives on topically similar but distinct prompts.
    |
    */
    'similarity_threshold' => (float) env('SEMANTIC_CACHE_THRESHOLD', 0.92),

    /*
    |--------------------------------------------------------------------------
    | TTL (Days)
    |--------------------------------------------------------------------------
    |
    | How many days to keep cache entries. Set to 0 to never expire.
    |
    */
    'ttl_days' => (int) env('SEMANTIC_CACHE_TTL_DAYS', 7),

];
