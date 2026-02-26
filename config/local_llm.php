<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local LLM HTTP Endpoints
    |--------------------------------------------------------------------------
    |
    | Enable support for Ollama and OpenAI-compatible local LLM endpoints.
    | When disabled, these providers are hidden from agent/skill forms.
    |
    */
    'enabled' => env('LOCAL_LLM_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | SSRF Protection
    |--------------------------------------------------------------------------
    |
    | When enabled, requests to link-local and other restricted IP ranges are
    | blocked. Automatically enabled in non-local environments.
    | Set LOCAL_LLM_SSRF_PROTECTION=false to allow private network IPs
    | (e.g. if your Ollama server is on a LAN address like 192.168.x.x).
    |
    */
    'ssrf_protection' => env('LOCAL_LLM_SSRF_PROTECTION', env('APP_ENV', 'production') !== 'local'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for local LLM inference requests.
    | Large models can be slow — increase if you experience timeouts.
    |
    */
    'timeout' => (int) env('LOCAL_LLM_TIMEOUT', 180),

];
