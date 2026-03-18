<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Allowed Social Login Providers
    |--------------------------------------------------------------------------
    |
    | The list of Socialite provider driver names that are accepted by
    | SocialAuthController. Plugins can extend this via FleetPluginServiceProvider
    | by declaring a $socialiteProviders array, which is merged here during boot.
    |
    */
    'providers' => ['google', 'github', 'linkedin-openid', 'x', 'apple', 'lukanet'],

    /*
    |--------------------------------------------------------------------------
    | PKCE-Enabled Providers
    |--------------------------------------------------------------------------
    |
    | Providers that use PKCE (Proof Key for Code Exchange). The standard
    | OAuth2 providers below support it. Custom providers should add their
    | driver name here if their server requires PKCE.
    |
    */
    'pkce_providers' => ['google', 'linkedin-openid', 'apple', 'lukanet'],

    /*
    |--------------------------------------------------------------------------
    | UI Metadata per Provider
    |--------------------------------------------------------------------------
    |
    | Display label and layout hints for each provider button.
    | full_width: true makes the button span both columns (like Apple).
    | Unknown providers fall back to ['label' => ucfirst($driver)].
    |
    | Plugins can extend this by merging into the config:
    |   config(['social.ui.lukanet' => ['label' => 'LukaNet']]);
    |
    */
    'ui' => [
        'google' => ['label' => 'Google'],
        'github' => ['label' => 'GitHub'],
        'linkedin-openid' => ['label' => 'LinkedIn'],
        'x' => ['label' => 'X'],
        'apple' => ['label' => 'Apple', 'full_width' => true],
        'lukanet' => ['label' => 'Lukanet', 'full_width' => true],
    ],
];
