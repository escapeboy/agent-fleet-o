<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;
use Illuminate\Http\Client\RequestException;

class ExecuteIntegrationActionAction
{
    public function __construct(
        private readonly IntegrationManager $manager,
    ) {}

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $driver = $this->manager->driver($integration->getAttribute('driver'));

        try {
            return $driver->execute($integration, $action, $params);
        } catch (RequestException $e) {
            $httpStatus = $e->response->status();

            if ($httpStatus === 401 || $httpStatus === 403) {
                $integration->update([
                    'status' => IntegrationStatus::Error,
                    'last_ping_message' => 'Authentication failed — please reconnect this integration.',
                    'last_pinged_at' => now(),
                    'error_count' => ($integration->error_count ?? 0) + 1,
                ]);
            }

            throw $e;
        }
    }
}
