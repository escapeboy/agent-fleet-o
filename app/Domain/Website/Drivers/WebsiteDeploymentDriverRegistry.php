<?php

namespace App\Domain\Website\Drivers;

use App\Domain\Website\Contracts\WebsiteDeploymentDriver;
use App\Domain\Website\Enums\DeploymentProvider;
use App\Domain\Website\Exceptions\DeploymentDriverException;

class WebsiteDeploymentDriverRegistry
{
    /**
     * @var array<string, WebsiteDeploymentDriver>
     */
    private array $drivers = [];

    public function register(WebsiteDeploymentDriver $driver): void
    {
        $this->drivers[$driver->provider()->value] = $driver;
    }

    public function resolve(DeploymentProvider $provider): WebsiteDeploymentDriver
    {
        if (! isset($this->drivers[$provider->value])) {
            throw new DeploymentDriverException(
                "No deployment driver registered for provider '{$provider->value}'",
            );
        }

        return $this->drivers[$provider->value];
    }

    /**
     * @return array<int, string>
     */
    public function availableProviders(): array
    {
        return array_keys($this->drivers);
    }
}
