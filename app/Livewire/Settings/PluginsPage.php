<?php

namespace App\Livewire\Settings;

use App\Contracts\HasHealthCheck;
use App\Domain\Shared\Models\PluginState;
use App\Domain\Shared\Services\PluginRegistry;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Admin page listing all installed plugins with enable/disable toggles.
 */
class PluginsPage extends Component
{
    /**
     * Plugins are platform-wide state — toggling them affects every tenant.
     * The `access-admin` gate restricts this to super-admins on cloud;
     * community / single-tenant deployments retain "any authenticated user"
     * because the gate resolves to true there (no super-admin role to gate
     * against).
     */
    public function mount(): void
    {
        Gate::authorize('access-admin');
    }

    public function togglePlugin(string $pluginId): void
    {
        Gate::authorize('access-admin');

        $state = PluginState::where('plugin_id', $pluginId)->first();
        if ($state) {
            $state->update(['enabled' => ! $state->enabled]);
        }
    }

    public function render()
    {
        $registry = app(PluginRegistry::class);
        $plugins = $registry->all();

        $rows = [];
        foreach ($plugins as $plugin) {
            $state = PluginState::where('plugin_id', $plugin->getId())->first();

            $health = null;
            if ($plugin instanceof HasHealthCheck) {
                try {
                    $health = $plugin->check();
                } catch (\Throwable) {
                    //
                }
            }

            $rows[] = [
                'plugin' => $plugin,
                'enabled' => $state->enabled ?? true,
                'version' => $state->version ?? $plugin->getVersion(),
                'installed_at' => $state?->installed_at,
                'health' => $health,
            ];
        }

        return view('livewire.settings.plugins-page', [
            'rows' => $rows,
            'total' => count($rows),
        ]);
    }
}
