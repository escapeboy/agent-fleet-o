<?php

/**
 * Deep Research workflow (borrowed from Onyx's multi-step research flow).
 * Dark-shipped: the MCP build/benchmark tools refuse to run until enabled.
 */

return [

    'enabled' => env('DEEP_RESEARCH_ENABLED', false),

    // Default workflow name (idempotency key per team).
    'workflow_name' => env('DEEP_RESEARCH_WORKFLOW_NAME', 'Deep Research'),

];
