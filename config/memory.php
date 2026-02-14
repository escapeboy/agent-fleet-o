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

];
