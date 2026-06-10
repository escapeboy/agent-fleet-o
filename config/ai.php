<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => env('AI_DEFAULT_PROVIDER', 'anthropic'),
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',

    /*
    |--------------------------------------------------------------------------
    | Internal Classification LLM
    |--------------------------------------------------------------------------
    |
    | Provider/model for the platform's internal classification operations
    | (signal intent classification, Sentry watchdog triage). When set, these
    | use this key directly instead of per-team provider resolution — they are
    | platform infrastructure, not team-billed work. Leave unset to fall back
    | to ProviderResolver.
    |
    */

    'classification' => [
        'provider' => env('AI_CLASSIFICATION_PROVIDER'),
        'model' => env('AI_CLASSIFICATION_MODEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),

            /*
             * OpenRouter requires vendor-prefixed model IDs (anthropic/claude-sonnet-4.5).
             * Agents are often configured with FleetQ's canonical IDs (claude-sonnet-4-5),
             * which OpenRouter rejects with "not a valid model ID" — tripping the per-agent
             * circuit breaker. The gateway auto-translates a canonical ID to its OpenRouter
             * equivalent via this map before the call. IDs already containing "/" pass through
             * untouched, so users can still set a raw OpenRouter ID directly.
             */
            'model_aliases' => [
                'claude-sonnet-4-5' => 'anthropic/claude-sonnet-4.5',
                'claude-sonnet-4-6' => 'anthropic/claude-sonnet-4.6',
                'claude-haiku-4-5' => 'anthropic/claude-haiku-4.5',
                'claude-opus-4-6' => 'anthropic/claude-opus-4.6',
                'claude-opus-4-7' => 'anthropic/claude-opus-4.7',
                'claude-opus-4-8' => 'anthropic/claude-opus-4.8',
                'gpt-4o' => 'openai/gpt-4o',
                'gpt-4o-mini' => 'openai/gpt-4o-mini',
                'gemini-2.5-flash' => 'google/gemini-2.5-flash',
                'gemini-2.5-pro' => 'google/gemini-2.5-pro',
            ],
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

];
