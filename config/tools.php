<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Semantic Tool Filtering
    |--------------------------------------------------------------------------
    |
    | When an agent has more PrismPHP tools than the threshold, the system
    | will use pgvector cosine similarity to pre-filter tools by relevance
    | to the user's input before starting LLM inference.
    |
    */

    'semantic_filter_threshold' => (int) env('TOOL_SEMANTIC_THRESHOLD', 15),

    'semantic_filter_limit' => (int) env('TOOL_SEMANTIC_LIMIT', 12),

    'semantic_filter_similarity' => (float) env('TOOL_SEMANTIC_SIMILARITY', 0.75),

    'embedding_provider' => env('TOOL_EMBEDDING_PROVIDER', 'openai'),

    'embedding_model' => env('TOOL_EMBEDDING_MODEL', 'text-embedding-3-small'),

];
