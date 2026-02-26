<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\Drivers\Generic\ApiPollingDriver;
use App\Domain\Integration\Drivers\Generic\WebhookOnlyDriver;
use Illuminate\Support\Manager;

/**
 * Laravel Manager that resolves integration drivers by slug.
 *
 * Usage:
 *   $driver = app(IntegrationManager::class)->driver('github');
 *   $driver = app(IntegrationManager::class)->driver('slack');
 *
 * New integrations are added by:
 *   1. Implementing IntegrationDriverInterface
 *   2. Adding a createXxxDriver() method here
 *   3. Registering the driver slug in config/integrations.php
 */
class IntegrationManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return config('integrations.default', 'webhook');
    }

    public function createApiPollingDriver(): IntegrationDriverInterface
    {
        return $this->container->make(ApiPollingDriver::class);
    }

    public function createWebhookDriver(): IntegrationDriverInterface
    {
        return $this->container->make(WebhookOnlyDriver::class);
    }
}
