<?php

namespace App\Console\Commands;

use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;
use App\Domain\Signal\Actions\IngestSignalAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollIntegrations extends Command
{
    protected $signature = 'integrations:poll {--driver= : Only poll integrations for a specific driver}';

    protected $description = 'Poll active integrations for new signals';

    public function __construct(
        private readonly IntegrationManager $manager,
        private readonly IngestSignalAction $ingestSignal,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Integration::withoutGlobalScopes()
            ->where('status', IntegrationStatus::Active->value);

        if ($driver = $this->option('driver')) {
            $query->where('driver', $driver);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->info('No active integrations to poll.');

            return self::SUCCESS;
        }

        $totalSignals = 0;

        foreach ($integrations as $integration) {
            /** @var Integration $integration */
            $driverSlug = $integration->getAttribute('driver');

            try {
                $driverInstance = $this->manager->driver($driverSlug);

                if ($driverInstance->pollFrequency() === 0) {
                    continue;
                }

                $teamId = $integration->getAttribute('team_id');
                $signals = $driverInstance->poll($integration);

                foreach ($signals as $signalData) {
                    $this->ingestSignal->execute(
                        sourceType: 'integration',
                        sourceIdentifier: $driverSlug,
                        payload: $signalData,
                        teamId: $teamId,
                    );
                    $totalSignals++;
                }

                if (count($signals) > 0) {
                    $this->info("{$integration->getAttribute('name')} ({$driverSlug}): ".count($signals).' signal(s)');
                }
            } catch (\Throwable $e) {
                Log::error('PollIntegrations: error polling integration', [
                    'integration_id' => $integration->getKey(),
                    'driver' => $driverSlug,
                    'error' => $e->getMessage(),
                ]);
                $this->error("{$integration->getAttribute('name')} ({$driverSlug}): {$e->getMessage()}");
            }
        }

        $this->info("Total: {$totalSignals} new signal(s) ingested.");

        return self::SUCCESS;
    }
}
