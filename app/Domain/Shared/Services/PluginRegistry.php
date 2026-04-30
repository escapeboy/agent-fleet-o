<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\PluginState;
use FleetQ\PluginSdk\Contracts\FleetPlugin;
use Illuminate\Support\Collection;

/**
 * Central registry for all installed FleetQ plugins.
 *
 * Bound as a singleton in AppServiceProvider.
 * FleetPluginServiceProvider::register() automatically registers the plugin here.
 */
class PluginRegistry
{
    /** @var array<string, FleetPlugin> */
    protected array $plugins = [];

    public function register(FleetPlugin $plugin): void
    {
        $this->plugins[$plugin->getId()] = $plugin;
    }

    /**
     * @return Collection<string, FleetPlugin>
     */
    public function all(): Collection
    {
        return collect($this->plugins);
    }

    public function find(string $id): ?FleetPlugin
    {
        return $this->plugins[$id] ?? null;
    }

    public function isEnabled(string $id): bool
    {
        if (! config('plugins.enabled', true)) {
            return false;
        }

        // Check database state if model is available (Phase 5)
        if (class_exists(PluginState::class)) {
            return PluginState::isEnabled($id);
        }

        return true;
    }

    public function count(): int
    {
        return count($this->plugins);
    }
}
