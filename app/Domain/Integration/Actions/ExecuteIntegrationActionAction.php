<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;

class ExecuteIntegrationActionAction
{
    public function __construct(
        private readonly IntegrationManager $manager,
    ) {}

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $driver = $this->manager->driver($integration->getAttribute('driver'));

        return $driver->execute($integration, $action, $params);
    }
}
