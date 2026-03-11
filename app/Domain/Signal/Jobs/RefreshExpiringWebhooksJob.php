<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Integration\Contracts\SubscribableConnectorInterface;
use App\Domain\Integration\Services\IntegrationManager;
use App\Domain\Signal\Models\ConnectorSignalSubscription;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-register webhooks that are expiring soon.
 *
 * Jira Cloud dynamic webhooks expire after ~30 days.
 * This job runs periodically and re-registers any subscription whose
 * webhook_expires_at is within the next 5 days.
 *
 * Scheduled: twice weekly via Console/Kernel.
 */
class RefreshExpiringWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(IntegrationManager $manager): void
    {
        $expiring = ConnectorSignalSubscription::with('integration.credential')
            ->expiringWebhooks()
            ->get();

        foreach ($expiring as $subscription) {
            $this->refresh($subscription, $manager);
        }
    }

    private function refresh(ConnectorSignalSubscription $subscription, IntegrationManager $manager): void
    {
        $integration = $subscription->integration;

        if (! $integration || ! $integration->isActive()) {
            return;
        }

        $driver = $manager->driver($subscription->driver);

        if (! $driver instanceof SubscribableConnectorInterface) {
            return;
        }

        try {
            // Deregister the old webhook first
            if ($subscription->webhook_id) {
                $driver->deregisterWebhook($integration, $subscription->webhook_id, $subscription->filter_config ?? []);
            }

            // Register a fresh webhook
            $registration = $driver->registerWebhook(
                integration: $integration,
                filterConfig: $subscription->filter_config ?? [],
                callbackUrl: $subscription->webhookUrl(),
            );

            $subscription->update([
                'webhook_id' => $registration->webhookId,
                'webhook_secret' => $registration->webhookSecret,
                'webhook_status' => 'registered',
                'webhook_expires_at' => $registration->expiresAt
                    ? Carbon::instance($registration->expiresAt)
                    : null,
            ]);

            Log::info('RefreshExpiringWebhooksJob: webhook refreshed', [
                'subscription_id' => $subscription->id,
                'driver' => $subscription->driver,
                'new_webhook_id' => $registration->webhookId,
            ]);
        } catch (\Throwable $e) {
            $subscription->update(['webhook_status' => 'failed']);

            Log::error('RefreshExpiringWebhooksJob: refresh failed', [
                'subscription_id' => $subscription->id,
                'driver' => $subscription->driver,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
