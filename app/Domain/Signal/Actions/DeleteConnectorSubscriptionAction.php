<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Jobs\DeregisterSubscriptionWebhookJob;
use App\Domain\Signal\Models\ConnectorSignalSubscription;

/**
 * Delete a ConnectorSignalSubscription and deregister the provider webhook.
 *
 * Dispatches DeregisterSubscriptionWebhookJob to clean up the webhook at
 * the provider (prevents orphaned webhooks accumulating over time).
 */
class DeleteConnectorSubscriptionAction
{
    public function execute(ConnectorSignalSubscription $subscription): void
    {
        // Dispatch deregistration BEFORE deleting the record so the job can
        // still read webhook_id, driver, and integration credentials.
        if ($subscription->webhook_id && $subscription->isWebhookRegistered()) {
            DeregisterSubscriptionWebhookJob::dispatch(
                $subscription->id,
                $subscription->webhook_id,
                $subscription->driver,
                $subscription->integration_id,
                $subscription->filter_config ?? [],
            );
        }

        $subscription->delete();
    }
}
