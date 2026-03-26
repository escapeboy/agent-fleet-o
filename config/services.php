<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
        'webhook_url' => env('SLACK_WEBHOOK_URL'),
    ],

    'plausible' => [
        'domain' => env('PLAUSIBLE_DOMAIN'),
    ],

    'google_analytics' => [
        'id' => env('GOOGLE_ANALYTICS_ID'),
    ],

    'sentry' => [
        'dsn' => env('SENTRY_LARAVEL_DSN'),
    ],

    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    ],

    'discord' => [
        'bot_token' => env('DISCORD_BOT_TOKEN'),
        'public_key' => env('DISCORD_PUBLIC_KEY'),
        'application_id' => env('DISCORD_APPLICATION_ID'),
    ],

    'teams' => [
        'webhook_url' => env('TEAMS_WEBHOOK_URL'),
    ],

    'google_chat' => [
        'webhook_url' => env('GOOGLE_CHAT_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform AI API Keys (immutable originals for BYOK key restoration)
    |--------------------------------------------------------------------------
    */
    'platform_api_keys' => [
        'anthropic' => env('ANTHROPIC_API_KEY'),
        'openai' => env('OPENAI_API_KEY'),
        'google' => env('GOOGLE_AI_API_KEY'),
        'groq' => env('GROQ_API_KEY'),
        'openrouter' => env('OPENROUTER_API_KEY'),
        'mistral' => env('MISTRAL_API_KEY'),
        'deepseek' => env('DEEPSEEK_API_KEY'),
        'xai' => env('XAI_API_KEY'),
        'perplexity' => env('PERPLEXITY_API_KEY'),
        'fireworks' => env('FIREWORKS_API_KEY'),
    ],

    'clearcue' => [
        'webhook_secret' => env('CLEARCUE_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Social Login (OAuth) Providers
    |--------------------------------------------------------------------------
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI'),
    ],

    'linkedin-openid' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect' => env('LINKEDIN_REDIRECT_URI'),
    ],

    'x' => [
        'client_id' => env('X_CLIENT_ID'),
        'client_secret' => env('X_CLIENT_SECRET'),
        'redirect' => env('X_REDIRECT_URI'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET', ''),
        'key_id' => env('APPLE_KEY_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    'lukanet' => [
        'client_id' => env('LUKANET_CLIENT_ID'),
        'client_secret' => env('LUKANET_CLIENT_SECRET'),
        'redirect' => env('LUKANET_REDIRECT_URI'),
    ],

    'searxng' => [
        'url' => env('SEARXNG_URL'),
    ],

];
