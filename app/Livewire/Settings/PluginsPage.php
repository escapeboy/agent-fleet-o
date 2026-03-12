<?php

namespace App\Livewire\Settings;

use App\Contracts\HasHealthCheck;
use App\Domain\Shared\Models\PluginState;
use App\Domain\Shared\Services\PluginRegistry;
use Livewire\Component;

/**
 * Admin page listing all installed plugins with enable/disable toggles.
 */
class PluginsPage extends Component
{
    public function togglePlugin(string $pluginId): void
    {
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
                'enabled' => $state?->enabled ?? true,
                'version' => $state?->version ?? $plugin->getVersion(),
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
