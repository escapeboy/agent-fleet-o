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
    'providers' => ['google', 'github', 'linkedin-openid', 'x', 'apple'],

    /*
    |--------------------------------------------------------------------------
    | Verified-Email Providers
    |--------------------------------------------------------------------------
    |
    | Socialite drivers whose returned email claim is considered pre-verified.
    | When a new user is auto-provisioned from one of these providers, their
    | `email_verified_at` is set immediately. Plugins that register corporate
    | IdPs (OIDC, SAML-via-Socialite, etc.) can extend this list from a service
    | provider, or operators can override it via the env var.
    |
    */
    'verified_email_providers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'SOCIAL_VERIFIED_EMAIL_PROVIDERS',
            'google,github,linkedin-openid,x,apple',
        )),
    ))),

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
    'pkce_providers' => ['google', 'linkedin-openid', 'apple'],

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
    ],
];
