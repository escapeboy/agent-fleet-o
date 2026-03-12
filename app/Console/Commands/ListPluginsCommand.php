<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\PluginState;
use App\Domain\Shared\Services\PluginRegistry;
use Illuminate\Console\Command;

/**
 * List all installed FleetQ plugins and their status.
 *
 * Usage:
 *   php artisan fleet:plugins
 */
class ListPluginsCommand extends Command
{
    protected $signature = 'fleet:plugins';

    protected $description = 'List all installed FleetQ plugins';

    public function handle(PluginRegistry $registry): int
    {
        $plugins = $registry->all();

        if (empty($plugins)) {
            $this->line('No plugins installed.');
            $this->newLine();
            $this->line('Install a plugin with:');
            $this->line('  composer require vendor/fleet-plugin-name');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($plugins as $plugin) {
            $rows[] = [
                $plugin->getId(),
                $plugin->getName(),
                $plugin->getVersion(),
                PluginState::isEnabled($plugin->getId()) ? '<fg=green>enabled</>' : '<fg=red>disabled</>',
            ];
        }

        $this->table(['ID', 'Name', 'Version', 'Status'], $rows);

        return self::SUCCESS;
    }
}
