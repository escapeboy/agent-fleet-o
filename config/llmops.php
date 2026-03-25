<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Langfuse LLMOps Trace Export
    |--------------------------------------------------------------------------
    |
    | When enabled, every AI gateway call is exported to Langfuse as a trace.
    | Failure to reach Langfuse never fails the AI request (fire-and-forget).
    |
    | LANGFUSE_PUBLIC_KEY and LANGFUSE_SECRET_KEY are required.
    | LANGFUSE_HOST defaults to Langfuse Cloud.
    |
    */
    'langfuse' => [
        'enabled' => ! empty(env('LANGFUSE_PUBLIC_KEY')),
        'host' => env('LANGFUSE_HOST', 'https://cloud.langfuse.com'),
        'public_key' => env('LANGFUSE_PUBLIC_KEY', ''),
        'secret_key' => env('LANGFUSE_SECRET_KEY', ''),
        // When true, system prompt and user prompt are replaced with [REDACTED]
        // before export. Enable if your prompts may contain PII or secrets.
        'mask_content' => (bool) env('LANGFUSE_MASK_CONTENT', false),
    ],

];
