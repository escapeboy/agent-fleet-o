<?php

namespace App\Domain\Website\Services;

use FleetQ\PluginSdk\Contracts\WebsiteBlockProvider;
use App\Domain\Shared\Services\PluginRegistry;

/**
 * Discovers all registered FleetQ plugins that implement WebsiteBlockProvider
 * and aggregates their GrapesJS block definitions, scripts, and styles.
 *
 * Bound as a singleton in AppServiceProvider.
 */
class WebsiteBlockRegistry
{
    public function __construct(
        private readonly PluginRegistry $plugins,
    ) {}

    /**
     * All GrapesJS block definitions from every active website plugin.
     *
     * @return array<string, array<string, mixed>>
     */
    public function blocks(): array
    {
        $blocks = [];

        foreach ($this->providers() as $provider) {
            foreach ($provider->getBlocks() as $type => $definition) {
                $blocks[$type] = $definition;
            }
        }

        return $blocks;
    }

    /**
     * All editor JS script URLs from every active website plugin.
     *
     * @return list<string>
     */
    public function scripts(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(fn ($p) => $p->getEditorScripts(), $this->providers()),
        )));
    }

    /**
     * All editor CSS stylesheet URLs from every active website plugin.
     *
     * @return list<string>
     */
    public function styles(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(fn ($p) => $p->getEditorStyles(), $this->providers()),
        )));
    }

    /**
     * @return list<WebsiteBlockProvider>
     */
    private function providers(): array
    {
        return $this->plugins
            ->all()
            ->filter(fn ($plugin) => $plugin instanceof WebsiteBlockProvider)
            ->values()
            ->all();
    }
}
