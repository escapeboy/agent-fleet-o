<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plugin System
    |--------------------------------------------------------------------------
    |
    | Set to false to disable all FleetQ plugins globally.
    | Individual plugins can be disabled via the plugin_states table (Phase 5).
    |
    */
    'enabled' => env('FLEET_PLUGINS', true),
];
