<?php

namespace App\Contracts;

use App\Domain\Shared\DTOs\PluginHealth;

/**
 * Optional interface for plugins that expose a health check.
 *
 * Implement this on your FleetPlugin class.
 * The HealthPage and `fleet:plugins` command will display the result.
 */
interface HasHealthCheck
{
    public function check(): PluginHealth;
}
