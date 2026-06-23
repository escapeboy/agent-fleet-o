<?php

/**
 * Web-search provider seam. Mirrors the embedding-provider seam
 * (config/memory.php `embedding_driver`): one interface, driver chosen by config,
 * resolved in AppServiceProvider. Default `searxng` preserves existing behaviour
 * (self-hosted, vendor-neutral). Additional providers are opt-in via BYOK key.
 */

return [

    'driver' => env('WEB_SEARCH_DRIVER', 'searxng'),

    'providers' => [

        'searxng' => [
            // URL resolved at runtime from GlobalSetting('searxng_url') first,
            // then this value. Operator-configured only (never agent-supplied) — SSRF.
            'url' => env('SEARXNG_URL'),
        ],

        'serper' => [
            'key' => env('SERPER_API_KEY'),
        ],

    ],

];
