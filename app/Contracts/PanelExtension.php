<?php

namespace App\Contracts;

use App\Domain\Shared\DTOs\NavigationItem;
use Livewire\Component;

/**
 * Optional interface for plugins that contribute UI elements to the platform.
 *
 * Implement this on your FleetPlugin class or a dedicated panel class.
 * Register it in your FleetPluginServiceProvider: $this->panels = [MyPanelExtension::class];
 */
interface PanelExtension
{
    /**
     * Livewire component classes to register as routable pages.
     *
     * @return list<class-string<Component>>
     */
    public function pages(): array;

    /**
     * Navigation items to inject into the sidebar.
     *
     * @return list<NavigationItem>
     */
    public function navigationItems(): array;

    /**
     * Livewire component classes to render as widgets on the dashboard.
     *
     * @return list<class-string>
     */
    public function dashboardWidgets(): array;
}
