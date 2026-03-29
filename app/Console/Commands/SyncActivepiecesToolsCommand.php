<?php

namespace App\Console\Commands;

use App\Domain\Integration\Actions\SyncActivepiecesToolsAction;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to sync Activepieces pieces into the Tool catalogue.
 *
 * Runs all active Activepieces integrations (or a specific one via --id).
 * Dispatched hourly from routes/console.php.
 */
class SyncActivepiecesToolsCommand extends Command
{
    protected $signature = 'integrations:sync-activepieces
                            {--id= : Only sync a specific integration UUID}';

    protected $description = 'Sync Activepieces pieces as MCP-HTTP tools for all active Activepieces integrations';

    public function __construct(
        private readonly SyncActivepiecesToolsAction $syncAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Integration::withoutGlobalScopes()
            ->where('driver', 'activepieces')
            ->where('status', IntegrationStatus::Active);

        if ($id = $this->option('id')) {
            $query->where('id', $id);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->info('No active Activepieces integrations found.');

            return self::SUCCESS;
        }

        $totalUpserted = 0;
        $totalDeactivated = 0;

        foreach ($integrations as $integration) {
            /** @var Integration $integration */
            try {
                $result = $this->syncAction->execute($integration);

                $totalUpserted += $result->upserted;
                $totalDeactivated += $result->deactivated;

                $this->info(
                    "✓ {$integration->getAttribute('name')}: ".$result->message,
                );
            } catch (\Throwable $e) {
                $this->error(
                    "✗ {$integration->getAttribute('name')}: ".$e->getMessage(),
                );
                Log::error('SyncActivepiecesToolsCommand: sync failed', [
                    'integration_id' => $integration->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Total — upserted: {$totalUpserted}, deactivated: {$totalDeactivated}.");

        return self::SUCCESS;
    }
}
