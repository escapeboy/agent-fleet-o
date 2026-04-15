<?php

return [
    'memory' => [
        /*
        |--------------------------------------------------------------------------
        | Memory Retrieval Settings
        |--------------------------------------------------------------------------
        */

        // Maximum number of memories to retrieve per chatbot request.
        'max_results' => env('CHAT_MEMORY_MAX_RESULTS', 5),

        // When true, only memories in curated tiers (canonical, facts, decisions,
        // failures, successes) are surfaced to the LLM context.
        'require_curated' => env('CHAT_MEMORY_REQUIRE_CURATED', true),

        /*
        |--------------------------------------------------------------------------
        | Topic Pre-filter (v1.19.0+)
        |--------------------------------------------------------------------------
        |
        | When enabled, the chatbot classifies the user query into a snake_case
        | topic slug (Haiku, cached per query hash for 1 hour) and narrows the
        | pgvector candidate set to memories sharing that topic BEFORE the scan.
        |
        | +20-35% retrieval precision in internal tests, but hard filter →
        | classifier disagreement drops recall to zero on mismatch. Ships OFF by
        | default. Enable per-env after verifying classifier/memory slug alignment.
        */
        'topic_filter_enabled' => env('CHAT_MEMORY_TOPIC_FILTER', false),

        // When topic-filtered retrieval returns zero results, retry without the
        // topic filter. Safety net for classifier/vocabulary drift. Default ON.
        'topic_filter_fallback_on_empty' => env('CHAT_MEMORY_TOPIC_FALLBACK', true),
    ],
];
