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
    ],

];
