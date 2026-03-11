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

    'clearcue' => [
        'webhook_secret' => env('CLEARCUE_WEBHOOK_SECRET'),
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
    ],
