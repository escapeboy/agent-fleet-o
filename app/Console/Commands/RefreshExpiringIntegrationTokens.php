<?php

namespace App\Console\Commands;

use App\Domain\Integration\Actions\RefreshIntegrationCredentialAction;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshExpiringIntegrationTokens extends Command
{
    protected $signature = 'integrations:refresh-tokens';

    protected $description = 'Refresh OAuth2 tokens for integrations expiring within 10 minutes';

    public function __construct(
        private readonly RefreshIntegrationCredentialAction $refreshAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $integrations = Integration::withoutGlobalScopes()
            ->where('status', IntegrationStatus::Active->value)
            ->whereNotNull('credential_id')
            ->get();

        $checked = 0;

        foreach ($integrations as $integration) {
            /** @var Integration $integration */
            $driver = (string) $integration->getAttribute('driver');

            // Skip non-OAuth2 drivers
            if (config("integrations.drivers.{$driver}.auth") !== 'oauth2') {
                continue;
            }

            try {
                $this->refreshAction->execute($integration);
                $checked++;
            } catch (\Throwable $e) {
                Log::error('RefreshExpiringIntegrationTokens: error', [
                    'integration_id' => $integration->getKey(),
                    'driver'         => $driver,
                    'error'          => $e->getMessage(),
                ]);
                $this->error("{$integration->getAttribute('name')}: {$e->getMessage()}");
            }
        }

        if ($checked > 0) {
            $this->info("Checked {$checked} OAuth2 integration(s) for token expiry.");
        } else {
            $this->info('No OAuth2 integrations to refresh.');
        }

        return self::SUCCESS;
    }
}
