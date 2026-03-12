<?php

namespace App\Domain\Shared\DTOs;

/**
 * A sidebar navigation item contributed by a plugin.
 *
 * Usage in FleetPluginServiceProvider::boot():
 *
 *   app(NavigationRegistry::class)->add(new NavigationItem(
 *       label: 'Analytics',
 *       route: 'fleet-analytics.dashboard',
 *       icon:  'chart-bar',
 *       order: 80,
 *   ));
 */
readonly class NavigationItem
{
    public function __construct(
        /** Human-readable label shown in the sidebar */
        public string $label,

        /** Named route (must be registered by the plugin's service provider) */
        public string $route,

        /** Heroicon name (without prefix), e.g. 'chart-bar', 'cog-6-tooth' */
        public string $icon,

        /** Optional sidebar group label (null = ungrouped at bottom) */
        public ?string $group = null,

        /** Lower numbers appear higher; core items use 0–99, plugins use 100+ */
        public int $order = 100,

        /** Gate ability that must pass for the item to be visible (null = always visible) */
        public ?string $permission = null,

        /** Optional badge text shown next to the label */
        public ?string $badge = null,
    ) {}
}
