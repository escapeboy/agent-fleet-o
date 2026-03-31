<?php

return [
    'enabled' => env('RAGFLOW_ENABLED', false),
    'url' => env('RAGFLOW_URL', 'http://ragflow:9380'),
    'api_key' => env('RAGFLOW_API_KEY', ''),
    'timeout' => 30,
    'chunk_method_default' => 'general',
    'embedding_model_default' => 'BAAI/bge-small-en-v1.5',
    'retrieval_top_k' => 8,
    'similarity_threshold' => 0.2,
    'vector_weight' => 0.3,
    'circuit_breaker_ttl' => 60,
    'circuit_breaker_threshold' => 5,
];
