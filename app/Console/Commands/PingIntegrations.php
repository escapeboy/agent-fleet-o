<?php

namespace App\Console\Commands;

use App\Domain\Integration\Actions\PingIntegrationAction;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PingIntegrations extends Command
{
    protected $signature = 'integrations:ping {--driver= : Only ping integrations for a specific driver}';

    protected $description = 'Health-check all active integrations';

    public function __construct(
        private readonly PingIntegrationAction $pingAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Integration::withoutGlobalScopes()
            ->whereIn('status', [IntegrationStatus::Active->value, IntegrationStatus::Error->value]);

        if ($driver = $this->option('driver')) {
            $query->where('driver', $driver);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->info('No active integrations to ping.');

            return self::SUCCESS;
        }

        $healthy = 0;
        $unhealthy = 0;

        foreach ($integrations as $integration) {
            /** @var Integration $integration */
            try {
                $result = $this->pingAction->execute($integration);

                if ($result->healthy) {
                    $healthy++;
                    $this->info("✓ {$integration->getAttribute('name')} ({$integration->getAttribute('driver')}) — {$result->latencyMs}ms");
                } else {
                    $unhealthy++;
                    $this->warn("✗ {$integration->getAttribute('name')} ({$integration->getAttribute('driver')}) — {$result->message}");
                }
            } catch (\Throwable $e) {
                $unhealthy++;
                Log::error('PingIntegrations: unexpected error', [
                    'integration_id' => $integration->getKey(),
                    'error' => $e->getMessage(),
                ]);
                $this->error("✗ {$integration->getAttribute('name')} — {$e->getMessage()}");
            }
        }

        $this->info("Done. {$healthy} healthy, {$unhealthy} unhealthy.");

        return self::SUCCESS;
    }
}
