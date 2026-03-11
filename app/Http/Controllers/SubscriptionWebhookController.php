<?php

namespace App\Http\Controllers;

use App\Domain\Integration\Contracts\SubscribableConnectorInterface;
use App\Domain\Integration\Services\IntegrationManager;
use App\Domain\Signal\Models\ConnectorSignalSubscription;
use App\Domain\Signal\Services\IntegrationSignalBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles inbound webhooks for ConnectorSignalSubscriptions.
 *
 * Route: POST /api/signals/subscription/{subscription}
 *
 * Unlike PerTeamSignalWebhookController (which uses a global per-team secret
 * stored in signal_connector_settings), this controller uses a per-subscription
 * webhook_secret so that each repo / Linear team / Jira project can be
 * individually verified and filtered.
 *
 * Authentication:
 *   1. Look up the subscription by UUID (in URL, not guessable).
 *   2. Verify HMAC signature using the subscription's webhook_secret.
 *   3. Delegate to IntegrationSignalBridge for filter_config application and ingestion.
 */
class SubscriptionWebhookController extends Controller
{
    public function __construct(
        private readonly IntegrationManager $manager,
        private readonly IntegrationSignalBridge $bridge,
    ) {}

    public function __invoke(Request $request, ConnectorSignalSubscription $subscription): JsonResponse
    {
        if (! $subscription->is_active) {
            return response()->json(['error' => 'Subscription is not active.'], 404);
        }

        $integration = $subscription->integration;

        if (! $integration || ! $integration->isActive()) {
            return response()->json(['error' => 'Integration not found or inactive.'], 404);
        }

        $driver = $this->manager->driver($subscription->driver);

        if (! $driver instanceof SubscribableConnectorInterface) {
            return response()->json(['error' => 'Driver does not support subscriptions.'], 400);
        }

        $rawBody = $request->getContent();
        $headers = collect($request->headers->all())
            ->map(fn ($values) => implode(', ', $values))
            ->all();

        $webhookSecret = $subscription->webhook_secret;

        if ($webhookSecret && ! $driver->verifySubscriptionSignature($rawBody, $headers, $webhookSecret)) {
            Log::warning('SubscriptionWebhookController: invalid signature', [
                'subscription_id' => $subscription->id,
                'driver' => $subscription->driver,
            ]);

            return response()->json(['error' => 'Invalid signature.'], 403);
        }

        $payload = $request->json()->all() ?: $request->all();

        $ingested = $this->bridge->handle($subscription, $payload, $headers);

        return response()->json(['ingested' => $ingested], $ingested > 0 ? 201 : 200);
    }
}
