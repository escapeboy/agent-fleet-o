<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Integration\Models\Integration;
use App\Domain\Signal\Jobs\RegisterSubscriptionWebhookJob;
use App\Domain\Signal\Models\ConnectorSignalSubscription;

/**
 * Create a ConnectorSignalSubscription that links an Integration (OAuth/API key
 * account) to the Signal ingestion pipeline with per-source filter configuration.
 *
 * After creating the record, dispatches RegisterSubscriptionWebhookJob to
 * register the webhook at the provider (for drivers that support it).
 */
class CreateConnectorSubscriptionAction
{
    /**
     * @param  array<string, mixed>  $filterConfig  Per-driver filter (repo, events, labels, etc.)
     */
    public function execute(
        Integration $integration,
        string $name,
        array $filterConfig = [],
    ): ConnectorSignalSubscription {
        $subscription = ConnectorSignalSubscription::create([
            'team_id' => $integration->team_id,
            'integration_id' => $integration->id,
            'driver' => $integration->driver,
            'name' => $name,
            'filter_config' => $filterConfig,
            'is_active' => true,
            'webhook_status' => 'pending',
        ]);

        // Dispatch webhook registration asynchronously.
        // The job will call driver->registerWebhook() and update webhook_id, webhook_secret.
        RegisterSubscriptionWebhookJob::dispatch($subscription->id);

        return $subscription;
    }
}
