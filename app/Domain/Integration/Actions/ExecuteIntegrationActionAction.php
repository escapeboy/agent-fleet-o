<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Events\IntegrationActionExecuted;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationActionGate;
use App\Domain\Integration\Services\IntegrationManager;
use Illuminate\Http\Client\RequestException;

class ExecuteIntegrationActionAction
{
    public function __construct(
        private readonly IntegrationManager $manager,
        private readonly IntegrationActionGate $gate,
    ) {}

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        // Sprint 3d.2: route through the per-tier risk policy gate before
        // any driver call. Throws IntegrationActionProposedException or
        // IntegrationActionRefusedException when policy demands; callers
        // must catch and surface to the user.
        $this->gate->check($integration, $action, $params);

        $driver = $this->manager->driver($integration->getAttribute('driver'));
        $start = microtime(true);

        try {
            $result = $driver->execute($integration, $action, $params);

            IntegrationActionExecuted::dispatch(
                integration: $integration,
                action: $action,
                params: $params,
                success: true,
                errorMessage: null,
                latencyMs: (int) ((microtime(true) - $start) * 1000),
            );

            return $result;
        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            if ($e instanceof RequestException) {
                $httpStatus = $e->response->status();

                if ($httpStatus === 401 || $httpStatus === 403) {
                    $integration->update([
                        'status' => IntegrationStatus::Error,
                        'last_ping_message' => 'Authentication failed — please reconnect this integration.',
                        'last_pinged_at' => now(),
                        'error_count' => ($integration->error_count ?? 0) + 1,
                    ]);
                }
            }

            IntegrationActionExecuted::dispatch(
                integration: $integration,
                action: $action,
                params: $params,
                success: false,
                errorMessage: $e->getMessage(),
                latencyMs: $latencyMs,
            );

            throw $e;
        }
    }
}
