<?php

namespace App\Domain\Signal\Services;

use App\Domain\Integration\Contracts\SubscribableConnectorInterface;
use App\Domain\Integration\Services\IntegrationManager;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Models\ConnectorSignalSubscription;
use Illuminate\Support\Facades\Log;

/**
 * Routes inbound webhook payloads received at /api/signals/subscription/{id}
 * through filter_config and into the Signal ingestion pipeline.
 *
 * Flow:
 *   SubscriptionWebhookController verifies HMAC signature
 *   → IntegrationSignalBridge::handle()
 *   → driver->mapPayloadToSignalDTO($payload, $headers, $filterConfig)
 *   → IngestSignalAction::execute()
 */
class IntegrationSignalBridge
{
    public function __construct(
        private readonly IntegrationManager $manager,
        private readonly IngestSignalAction $ingestSignal,
    ) {}

    /**
     * Process a verified webhook payload for a single subscription.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function handle(ConnectorSignalSubscription $subscription, array $payload, array $headers): int
    {
        $driver = $this->manager->driver($subscription->driver);

        if (! $driver instanceof SubscribableConnectorInterface) {
            Log::warning('IntegrationSignalBridge: driver does not implement SubscribableConnectorInterface', [
                'driver' => $subscription->driver,
                'subscription_id' => $subscription->id,
            ]);

            return 0;
        }

        $signalDto = $driver->mapPayloadToSignalDTO(
            payload: $payload,
            headers: $headers,
            filterConfig: $subscription->filter_config ?? [],
        );

        if ($signalDto === null) {
            // Filtered out by driver logic (e.g. event type not subscribed, label mismatch)
            return 0;
        }

        try {
            $signal = $this->ingestSignal->execute(
                sourceType: $subscription->driver,
                sourceIdentifier: $signalDto->sourceIdentifier,
                payload: $signalDto->payload,
                tags: $signalDto->tags,
                sourceNativeId: $signalDto->sourceNativeId,
                teamId: $subscription->team_id,
            );

            if ($signal) {
                $subscription->increment('signal_count');
                $subscription->update(['last_signal_at' => now()]);
            }

            return $signal ? 1 : 0;
        } catch (\Throwable $e) {
            Log::error('IntegrationSignalBridge: signal ingest failed', [
                'subscription_id' => $subscription->id,
                'driver' => $subscription->driver,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
