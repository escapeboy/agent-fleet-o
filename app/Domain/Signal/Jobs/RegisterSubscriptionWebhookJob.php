<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Integration\Contracts\SubscribableConnectorInterface;
use App\Domain\Integration\Services\IntegrationManager;
use App\Domain\Signal\Models\ConnectorSignalSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Register a webhook at the provider for a ConnectorSignalSubscription.
 *
 * Runs after CreateConnectorSubscriptionAction. Calls driver->registerWebhook()
 * and persists webhook_id, webhook_secret, and webhook_expires_at on the
 * subscription record.
 *
 * If the driver does not implement SubscribableConnectorInterface (e.g. plain
 * HMAC webhook drivers), the job exits cleanly without error.
 */
class RegisterSubscriptionWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $subscriptionId,
    ) {
        $this->onQueue('default');
    }

    public function handle(IntegrationManager $manager): void
    {
        $subscription = ConnectorSignalSubscription::with('integration.credential')
            ->find($this->subscriptionId);

        if (! $subscription || ! $subscription->is_active) {
            return;
        }

        $integration = $subscription->integration;

        if (! $integration || ! $integration->isActive()) {
            Log::warning('RegisterSubscriptionWebhookJob: integration not found or inactive', [
                'subscription_id' => $this->subscriptionId,
            ]);

            return;
        }

        $driver = $manager->driver($subscription->driver);

        if (! $driver instanceof SubscribableConnectorInterface) {
            // Driver does not support auto-registration. Mark as manually-managed.
            $subscription->update(['webhook_status' => 'manual']);

            return;
        }

        try {
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
                    ? \Carbon\Carbon::instance($registration->expiresAt)
                    : null,
            ]);

            Log::info('RegisterSubscriptionWebhookJob: webhook registered', [
                'subscription_id' => $this->subscriptionId,
                'driver' => $subscription->driver,
                'webhook_id' => $registration->webhookId,
            ]);
        } catch (\Throwable $e) {
            $subscription->update(['webhook_status' => 'failed']);

            Log::error('RegisterSubscriptionWebhookJob: registration failed', [
                'subscription_id' => $this->subscriptionId,
                'driver' => $subscription->driver,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
