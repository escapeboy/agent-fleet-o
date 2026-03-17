<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Current Terms Version
    |--------------------------------------------------------------------------
    |
    | Integer version number of the currently active Terms of Service.
    | When this value is greater than a user's stored terms_version, they will
    | be asked to accept the new terms before accessing the platform.
    |
    | Set to 0 to disable enforcement (no re-acceptance required).
    | Bump to 1 (and run config:cache) to enforce acceptance from all users.
    |
    */
    'current_version' => (int) env('TERMS_CURRENT_VERSION', 1),
];
