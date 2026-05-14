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

    /*
    |--------------------------------------------------------------------------
    | Arize Phoenix OTLP Trace Export
    |--------------------------------------------------------------------------
    |
    | When PHOENIX_OTLP_ENDPOINT is set, every AI gateway call is exported as
    | an OpenInference-shaped OTLP trace to the Phoenix instance. Failure to
    | reach Phoenix never fails the AI request (fire-and-forget).
    |
    | Docker-internal sidecar (PHOENIX_OTLP_ENDPOINT=http://phoenix:6006) is
    | the expected default — set PHOENIX_ALLOW_HTTP=true so the http:// scheme
    | is permitted. Public endpoints must be https.
    |
    */
    'phoenix' => [
        'enabled' => ! empty(env('PHOENIX_OTLP_ENDPOINT')),
        'endpoint' => env('PHOENIX_OTLP_ENDPOINT', ''),
        'api_key' => env('PHOENIX_API_KEY', ''),
        'allow_http' => (bool) env('PHOENIX_ALLOW_HTTP', false),
        'project' => env('PHOENIX_PROJECT_NAME', 'fleetq'),
    ],

];
