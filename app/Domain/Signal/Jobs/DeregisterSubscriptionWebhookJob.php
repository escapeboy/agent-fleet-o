<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Integration\Contracts\SubscribableConnectorInterface;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Deregister a webhook at the provider when a ConnectorSignalSubscription is deleted.
 *
 * Uses IDs and config copied at dispatch time (not a model reference) because the
 * subscription record is deleted before this job runs.
 */
class DeregisterSubscriptionWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $filterConfig
     */
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $webhookId,
        public readonly string $driver,
        public readonly string $integrationId,
        public readonly array $filterConfig,
    ) {
        $this->onQueue('default');
    }

    public function handle(IntegrationManager $manager): void
    {
        $integration = Integration::with('credential')->find($this->integrationId);

        if (! $integration) {
            Log::info('DeregisterSubscriptionWebhookJob: integration not found, skipping', [
                'integration_id' => $this->integrationId,
                'webhook_id' => $this->webhookId,
            ]);

            return;
        }

        $driverInstance = $manager->driver($this->driver);

        if (! $driverInstance instanceof SubscribableConnectorInterface) {
            return;
        }

        try {
            $driverInstance->deregisterWebhook(
                integration: $integration,
                webhookId: $this->webhookId,
                filterConfig: $this->filterConfig,
            );

            Log::info('DeregisterSubscriptionWebhookJob: webhook deregistered', [
                'subscription_id' => $this->subscriptionId,
                'driver' => $this->driver,
                'webhook_id' => $this->webhookId,
            ]);
        } catch (\Throwable $e) {
            // Log but don't rethrow — a failed deregistration should not block the deletion.
            Log::warning('DeregisterSubscriptionWebhookJob: deregistration failed', [
                'subscription_id' => $this->subscriptionId,
                'webhook_id' => $this->webhookId,
                'driver' => $this->driver,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
