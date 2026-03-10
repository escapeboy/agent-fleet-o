<?php

return [
    /*
     * Relying Party — must match the domain the app is served on.
     * localhost in dev, fleetq.net in production.
     */
    'rp' => [
        'name' => env('APP_NAME', 'FleetQ'),
        'id' => env('WEBAUTHN_ID', 'localhost'),
    ],

    /*
     * User verification preference.
     * 'required' | 'preferred' | 'discouraged'
     */
    'user_verification' => 'preferred',

    /*
     * Attestation conveyance preference.
     * 'none' | 'indirect' | 'direct' | 'enterprise'
     */
    'attestation_conveyance' => 'none',

    /*
     * Timeout for WebAuthn ceremony in milliseconds.
     */
    'timeout' => 60000,

    /*
     * Cache store used for challenge storage during ceremony.
     * MUST be a persistent store — 'array' and 'null' won't work.
     * Use 'redis' (recommended) or 'database'.
     */
    'cache' => env('WEBAUTHN_CACHE_STORE', 'redis'),

    /*
     * Algorithm COSE identifiers supported for credential creation.
     * ES256 = -7, RS256 = -257 (covers virtually all authenticators).
     * Using literals here so this file loads before the package is installed.
     */
    'public_key_credential_parameters' => [-7, -257],

    /*
     * Authenticator selection criteria for registration.
     */
    'authenticator_selection_criteria' => [
        'authenticator_attachment' => null, // null = platform + cross-platform
        'resident_key' => 'preferred',
        'require_resident_key' => false,
        'user_verification' => 'preferred',
    ],
];
