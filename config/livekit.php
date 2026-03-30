<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LiveKit Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for connecting to a LiveKit server. Supports both
    | LiveKit Cloud (wss://your-project.livekit.cloud) and self-hosted
    | LiveKit instances. Set LIVEKIT_URL to point to your instance.
    |
    */

    'url' => env('LIVEKIT_URL', 'wss://your-project.livekit.cloud'),

    'api_key' => env('LIVEKIT_API_KEY'),

    'api_secret' => env('LIVEKIT_API_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Token TTL
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) a LiveKit JWT token is valid. Tokens are issued
    | per participant per session. Default: 3600 (1 hour).
    |
    */

    'token_ttl' => (int) env('LIVEKIT_TOKEN_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Speech-to-Text Provider
    |--------------------------------------------------------------------------
    */

    'stt' => [
        'provider' => env('VOICE_STT_PROVIDER', 'deepgram'), // deepgram|whisper
        'api_key' => env('DEEPGRAM_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Text-to-Speech Provider
    |--------------------------------------------------------------------------
    */

    'tts' => [
        'provider' => env('VOICE_TTS_PROVIDER', 'openai'), // openai|elevenlabs
        'api_key' => env('ELEVENLABS_API_KEY'),
        'voice_id' => env('VOICE_TTS_VOICE_ID', 'alloy'),
    ],
];
