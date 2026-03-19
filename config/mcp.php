<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    'redirect_domains' => [
        // Anthropic / Claude.ai
        'https://claude.ai',
        // OpenAI / ChatGPT Actions
        'https://chatgpt.com',
        'https://chat.openai.com',
        // Local development
        'http://localhost',
        'http://127.0.0.1',
    ],

];
