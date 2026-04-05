<?php

namespace App\Providers;

use App\Contracts\PanelExtension;
use FleetQ\PluginSdk\Contracts\FleetPlugin;
use App\Domain\Integration\Services\IntegrationManager;
use App\Domain\Outbound\Managers\OutboundConnectorManager;
use App\Domain\Shared\Models\PluginState;
use App\Domain\Shared\Services\NavigationRegistry;
use App\Domain\Shared\Services\PluginRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Base service provider for all FleetQ plugins.
 *
 * Plugin authors extend this class instead of Laravel's ServiceProvider.
 * Declarative arrays handle common registration tasks with zero boilerplate.
 *
 * Minimum plugin service provider:
 *
 *   class MyPluginServiceProvider extends FleetPluginServiceProvider
 *   {
 *       protected array $listen = [
 *           SignalIngested::class => [MySingalListener::class],
 *       ];
 *
 *       protected function createPlugin(): FleetPlugin
 *       {
 *           return new MyPlugin;
 *       }
 *   }
 *
 * Auto-discovery via composer.json:
 *
 *   "extra": {
 *     "laravel": { "providers": ["Acme\\MyPlugin\\MyPluginServiceProvider"] },
 *     "fleet":   { "plugin": "my-plugin", "name": "My Plugin", "min-version": "1.0.0" }
 *   }
 */
abstract class FleetPluginServiceProvider extends ServiceProvider
{
    // -------------------------------------------------------------------------
    // Declarative registration — override these arrays in your plugin provider
    // -------------------------------------------------------------------------

    /** @var array<class-string, list<class-string>> EventClass => [ListenerClass, ...] */
    protected array $listen = [];

    /** @var list<class-string> MCP Tool FQCNs to register (tagged as 'fleet.mcp.tools') */
    protected array $mcpTools = [];

    /**
     * Livewire component namespaces to register.
     * Key = namespace alias, value = fully-qualified component class namespace.
     * Example: ['fleet-analytics' => 'FleetAnalytics\\Livewire']
     *
     * @var array<string, string>
     */
    protected array $livewire = [];

    /** @var list<class-string> InputConnectorInterface implementations (tagged as 'fleet.signal.connectors') */
    protected array $signals = [];

    /** @var list<class-string> OutboundConnectorInterface implementations (tagged as 'fleet.outbound.connectors') */
    protected array $outbound = [];

    /** @var list<class-string> Artisan command classes */
    protected array $commands = [];

    /** @var list<class-string> AiMiddlewareInterface implementations (tagged as 'fleet.ai.middleware') */
    protected array $aiMiddleware = [];

    /** @var list<class-string> IntegrationDriverInterface implementations (tagged as 'fleet.integrations') */
    protected array $integrations = [];

    /** @var list<class-string<PanelExtension>> PanelExtension implementations */
    protected array $panels = [];

    /**
     * Socialite provider driver names to add to the social login allowlist.
     * Each driver must also be registered (e.g. in bootAddon() via Socialite::extend()).
     *
     * Example:
     *   protected array $socialiteProviders = ['lukanet'];
     *
     * @var list<string>
     */
    protected array $socialiteProviders = [];

    // -------------------------------------------------------------------------
    // Abstract — plugin authors must implement this
    // -------------------------------------------------------------------------

    abstract protected function createPlugin(): FleetPlugin;

    // -------------------------------------------------------------------------
    // ServiceProvider lifecycle
    // -------------------------------------------------------------------------

    public function register(): void
    {
        $plugin = $this->createPlugin();

        // Register as a named singleton so other code can resolve this plugin by ID
        $this->app->singleton("fleet.plugin.{$plugin->getId()}", fn () => $plugin);

        // Register in the global registry (only if not disabled)
        if ($this->app->bound(PluginRegistry::class)) {
            $this->app->make(PluginRegistry::class)->register($plugin);
        }

        // Tag connectors and tools for the registries
        $this->registerTaggedBindings();

        // Merge plugin's Socialite providers into the social allowlist
        if (! empty($this->socialiteProviders)) {
            $existing = config('social.providers', []);
            config(['social.providers' => array_values(array_unique(array_merge($existing, $this->socialiteProviders)))]);
        }

        // Let the plugin do its own container bindings
        $plugin->register();
    }

    public function boot(): void
    {
        // Register/update plugin state (creates the row on first boot, no-op if table missing)
        if (class_exists(PluginState::class)) {
            try {
                $plugin = $this->createPlugin();
                PluginState::register(
                    $plugin->getId(),
                    $plugin->getName(),
                    $plugin->getVersion(),
                );
            } catch (\Throwable) {
                // Table might not exist yet on fresh install — ignore
            }
        }

        // Skip all boot logic if disabled in plugin_states
        if (! $this->isPluginEnabled()) {
            return;
        }

        $plugin = $this->createPlugin();

        // Boot declarative registrations
        $this->bootListeners();
        $this->bootLivewireComponents();
        $this->bootPanelExtensions();
        $this->bootOutboundConnectors();
        $this->bootIntegrationDrivers();

        if ($this->app->runningInConsole()) {
            $this->bootCommands();
        }

        // Allow subclasses to add boot logic without overriding the full boot()
        $this->bootAddon();

        // Let the plugin do its own boot logic
        $plugin->boot();
    }

    /**
     * Empty hook for subclasses — runs at the end of boot() after all declarations
     * have been processed. Override this instead of boot() to avoid accidentally
     * skipping disable checks or declarative registration.
     */
    protected function bootAddon(): void
    {
        // intentionally empty
    }

    // -------------------------------------------------------------------------
    // Private / protected helpers
    // -------------------------------------------------------------------------

    protected function registerTaggedBindings(): void
    {
        if (! empty($this->signals)) {
            $this->app->tag($this->signals, 'fleet.signal.connectors');
        }

        if (! empty($this->outbound)) {
            // Tag as plugin-specific outbound connectors so SendOutboundAction can pick them up
            $this->app->tag($this->outbound, 'fleet.outbound.connectors.plugin');
        }

        if (! empty($this->mcpTools)) {
            $this->app->tag($this->mcpTools, 'fleet.mcp.tools');
            // Accumulate class names so AgentFleetServer::boot() can append them
            if ($this->app->bound('fleet.mcp.tool_classes')) {
                $existing = $this->app->make('fleet.mcp.tool_classes');
                $this->app->instance('fleet.mcp.tool_classes', array_merge($existing, $this->mcpTools));
            }
        }

        if (! empty($this->aiMiddleware)) {
            $this->app->tag($this->aiMiddleware, 'fleet.ai.middleware');
        }

        if (! empty($this->integrations)) {
            $this->app->tag($this->integrations, 'fleet.integrations');
        }
    }

    protected function bootListeners(): void
    {
        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    protected function bootLivewireComponents(): void
    {
        if (empty($this->livewire)) {
            return;
        }

        foreach ($this->livewire as $namespace => $componentNamespace) {
            // Derive the expected view path from the class namespace
            $parts = explode('\\', $componentNamespace);
            // We can't reliably derive a path from namespace alone;
            // plugin authors should use bootAddon() for full Livewire::addNamespace() calls
            // that require explicit paths. This handles the simple alias case.
            Livewire::addNamespace($namespace, $componentNamespace, null);
        }
    }

    protected function bootPanelExtensions(): void
    {
        if (empty($this->panels)) {
            return;
        }

        $nav = $this->app->make(NavigationRegistry::class);

        foreach ($this->panels as $panelClass) {
            /** @var PanelExtension $panel */
            $panel = $this->app->make($panelClass);

            foreach ($panel->navigationItems() as $item) {
                $nav->add($item);
            }
        }
    }

    protected function bootCommands(): void
    {
        if (! empty($this->commands)) {
            $this->commands($this->commands);
        }
    }

    /**
     * Register plugin outbound connectors with the OutboundConnectorManager.
     * Connectors that implement a getDriverName() method are registered by that name.
     * Others fall back to what supports() matches (first channel that returns true).
     */
    protected function bootOutboundConnectors(): void
    {
        if (empty($this->outbound) || ! $this->app->bound(OutboundConnectorManager::class)) {
            return;
        }

        $manager = $this->app->make(OutboundConnectorManager::class);

        foreach ($this->outbound as $connectorClass) {
            $connector = $this->app->make($connectorClass);
            $driverName = method_exists($connector, 'getDriverName')
                ? $connector->getDriverName()
                : null;

            if ($driverName) {
                $manager->extend($driverName, fn () => $connector);
            }
        }
    }

    /**
     * Register plugin integration drivers with the IntegrationManager.
     */
    protected function bootIntegrationDrivers(): void
    {
        if (empty($this->integrations)) {
            return;
        }

        if (! $this->app->bound(IntegrationManager::class)) {
            return;
        }

        $manager = $this->app->make(IntegrationManager::class);

        foreach ($this->integrations as $driverClass) {
            $driver = $this->app->make($driverClass);
            if (method_exists($driver, 'key')) {
                $manager->extend($driver->key(), fn () => $driver);
            }
        }
    }

    /**
     * Return the plugin ID — used for disable checks and registry keys.
     * Defaults to the ID returned by createPlugin().
     */
    protected function pluginId(): string
    {
        return $this->createPlugin()->getId();
    }

    /**
     * Check whether the plugin is enabled.
     * When the PluginState model is not available (e.g. before Phase 5 migration),
     * defaults to enabled.
     */
    protected function isPluginEnabled(): bool
    {
        if (! config('plugins.enabled', true)) {
            return false;
        }

        if (class_exists(PluginState::class)) {
            try {
                return PluginState::isEnabled($this->pluginId());
            } catch (\Throwable) {
                // Table might not exist yet during initial install
                return true;
            }
        }

        return true;
    }
}
