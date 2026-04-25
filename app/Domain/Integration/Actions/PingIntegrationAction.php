<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;

class PingIntegrationAction
{
    public function __construct(
        private readonly IntegrationManager $manager,
    ) {}

    public function execute(Integration $integration): HealthResult
    {
        $driver = $this->manager->driver($integration->getAttribute('driver'));
        $result = $driver->ping($integration);

        $meta = $integration->getAttribute('meta') ?? [];
        if ($result->healthy && $result->identity !== null) {
            $meta['account'] = array_merge($result->identity, [
                'verified_at' => now()->toIso8601String(),
            ]);
        }

        $integration->update([
            'last_pinged_at' => now(),
            'last_ping_status' => $result->healthy ? 'ok' : 'error',
            'last_ping_message' => $result->message,
            'error_count' => $result->healthy ? 0 : ($integration->error_count + 1),
            'status' => $result->healthy
                ? IntegrationStatus::Active
                : ($integration->error_count + 1 >= config('integrations.health.error_threshold', 5)
                    ? IntegrationStatus::Error
                    : $integration->status),
            'meta' => $meta,
        ]);

        return $result;
    }
}
