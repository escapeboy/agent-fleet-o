<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default LLM Provider & Model
    |--------------------------------------------------------------------------
    |
    | Used by internal actions (signal structuring, knowledge graph extraction,
    | entity extraction, etc.) when no explicit provider is specified. These
    | keys are intentionally outside `llm_providers.php` so the cloud edition's
    | provider filter loop is not fed scalar values.
    |
    | Resolve via App\Support\LlmDefaults — that helper cascades:
    |   config('llm_defaults.provider')
    |     → config('llm_providers.default_provider')   (Barsy-style override)
    |     → 'bridge_agent'                             (zero-config community default)
    |
    */

    'provider' => env('LLM_DEFAULT_PROVIDER'),

    'model' => env('LLM_DEFAULT_MODEL'),
];
