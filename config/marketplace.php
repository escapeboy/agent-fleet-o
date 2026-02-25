<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Remote Marketplace Registry
    |--------------------------------------------------------------------------
    |
    | The community edition can browse, search, and install skills, agents,
    | and workflows from the cloud marketplace. Set the registry URL to
    | your FleetQ cloud instance or the official registry.
    |
    */

    'registry_url' => env('MARKETPLACE_REGISTRY_URL', 'https://fleetq.net/api/v1/marketplace'),

    'api_key' => env('MARKETPLACE_API_KEY'),

    'enabled' => env('MARKETPLACE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum number of seconds to wait for a response from the marketplace
    | registry before timing out.
    |
    */

    'timeout' => env('MARKETPLACE_TIMEOUT', 15),

];
