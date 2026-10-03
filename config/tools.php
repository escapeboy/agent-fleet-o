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

    /*
    |--------------------------------------------------------------------------
    | MCP Definition Pinning
    |--------------------------------------------------------------------------
    |
    | When enabled, a change in an MCP server's tools/list after the definitions
    | were first stored is held as pending and an ActionProposal (target_type
    | mcp_tool_definitions) carries the diff until a person approves it.
    | Cloud-provider agents keep the approved set; local agents (Claude Code),
    | which read tools/list from the server directly, do not get the server at
    | all while a change is pending. Detection runs with tools:health-check
    | (every 5 minutes), so for local agents a change is live until then.
    |
    */

    'definition_pinning' => [
        'enabled' => env('MCP_DEFINITION_PINNING_ENABLED', false),
        'proposal_ttl_days' => (int) env('MCP_DEFINITION_PROPOSAL_TTL_DAYS', 7),
    ],

];
