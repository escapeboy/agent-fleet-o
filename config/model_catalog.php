<?php

/**
 * Dynamic model catalog sync for managed multi-model providers.
 *
 * When enabled, providers flagged `dynamic_catalog` in llm_providers.php have
 * their model list fetched live from the provider's /models endpoint (via
 * ManagedModelDiscovery) instead of the hardcoded static config catalog.
 *
 * Default OFF — zero behavior change until the env flag is flipped. The static
 * config catalog is always the fallback when the endpoint is unreachable.
 */
return [

    // Master switch. When false, managed providers keep their static config catalog.
    'enabled' => env('MANAGED_MODEL_CATALOG_SYNC', false),

    // Redis cache TTL (seconds) for a discovered catalog. Managed catalogs change
    // far less often than local Ollama pulls, so a longer TTL than LocalLlmDiscovery.
    'cache_ttl' => (int) env('MANAGED_MODEL_CATALOG_TTL', 3600),

    // HTTP timeout (seconds) for the /models fetch. Never blocks render — on
    // timeout the discovery returns [] and the caller falls back to static config.
    'timeout' => (int) env('MANAGED_MODEL_CATALOG_TIMEOUT', 5),

    // Conservative wildcard price (USD per 1M tokens) applied to a synced model
    // that has NO pricing from the provider and NO config entry — so an unpriced
    // model is never silently billed at $0 (ledger-integrity guard, FR-2.2/NFR-4).
    'unpriced_fallback' => [
        'input_usd_per_mtok' => (float) env('MANAGED_MODEL_UNPRICED_INPUT', 10.0),
        'output_usd_per_mtok' => (float) env('MANAGED_MODEL_UNPRICED_OUTPUT', 30.0),
    ],
];
