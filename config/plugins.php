<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plugin System
    |--------------------------------------------------------------------------
    |
    | Set to false to disable all FleetQ plugins globally.
    | Individual plugins can be disabled via the plugin_states table.
    |
    */
    'enabled' => env('FLEET_PLUGINS', true),

    /*
    |--------------------------------------------------------------------------
    | Cloud Plugin Whitelist
    |--------------------------------------------------------------------------
    |
    | Cloud edition only. Comma-separated list of plugin IDs the platform
    | operator has approved for tenant teams to use. Teams can opt-in/out of
    | these plugins via the /plugins page.
    |
    | Empty string = no plugins exposed to cloud teams (safe default).
    |
    | Example:
    |   FLEET_CLOUD_PLUGINS=acme-crm,acme-reporting
    |
    */
    'cloud_available' => env('FLEET_CLOUD_PLUGINS', ''),

    /*
    |--------------------------------------------------------------------------
    | Parsed Cloud Plugin IDs (derived)
    |--------------------------------------------------------------------------
    |
    | Parsed array of cloud_available. Use config('plugins.cloud_available_ids')
    | anywhere you need an array of approved plugin IDs.
    |
    */
    'cloud_available_ids' => array_values(array_filter(
        array_map('trim', explode(',', env('FLEET_CLOUD_PLUGINS', ''))),
    )),

    /*
    |--------------------------------------------------------------------------
    | External Plugin Providers
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of fully-qualified service provider class names to
    | register at boot. Allows external packages (e.g. Barsy plugins) to hook
    | into FleetQ without modifying this codebase or its composer.json.
    |
    | The package is responsible for its own autoloading (e.g. via its own
    | composer.json). Only classes that already exist will be registered.
    |
    | Example (.env):
    |   FLEET_EXTERNAL_PLUGIN_PROVIDERS=Barsy\Plugins\BarsyChatbotPlugin
    |
    */
    'external_providers' => array_values(array_filter(
        array_map('trim', explode(',', env('FLEET_EXTERNAL_PLUGIN_PROVIDERS', ''))),
    )),
];
