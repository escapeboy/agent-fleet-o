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
    | PKCE-Enabled Providers
    |--------------------------------------------------------------------------
    |
    | Providers that use PKCE (Proof Key for Code Exchange). The standard
    | OAuth2 providers below support it. Custom providers should add their
    | driver name here if their server requires PKCE.
    |
    */
    'pkce_providers' => ['google', 'linkedin-openid', 'apple'],
];
