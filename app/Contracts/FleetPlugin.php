<?php

namespace App\Contracts;

/**
 * Contract for all FleetQ plugins.
 *
 * Plugins distributed as Composer packages implement this interface and declare
 * their service provider in composer.json using Laravel auto-discovery:
 *
 *   "extra": {
 *     "laravel": { "providers": ["Acme\\MyPlugin\\MyPluginServiceProvider"] },
 *     "fleet":   { "plugin": "my-plugin", "name": "My Plugin", "min-version": "1.0.0" }
 *   }
 *
 * The service provider should extend FleetPluginServiceProvider and return
 * an instance of this interface from createPlugin().
 */
interface FleetPlugin
{
    /**
     * Unique slug for this plugin (e.g. 'fleet-analytics').
     * Used as the plugin registry key and namespaced meta key prefix.
     */
    public function getId(): string;

    /**
     * Human-readable name (e.g. 'Fleet Analytics').
     */
    public function getName(): string;

    /**
     * Semantic version string (e.g. '1.0.0').
     */
    public function getVersion(): string;

    /**
     * Called during the service provider's register() phase.
     * Use only for container bindings — no event listeners, routes, or macros here.
     */
    public function register(): void;

    /**
     * Called during the service provider's boot() phase.
     * Safe to register event listeners, routes, blade directives, etc.
     */
    public function boot(): void;
}
