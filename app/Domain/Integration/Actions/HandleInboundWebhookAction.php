<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Models\WebhookRoute;
use App\Domain\Integration\Services\IntegrationManager;
use App\Domain\Integration\Services\WebhookVerifier;
use App\Domain\Signal\Actions\IngestSignalAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class HandleInboundWebhookAction
{
    public function __construct(
        private readonly IntegrationManager $manager,
        private readonly WebhookVerifier $verifier,
        private readonly IngestSignalAction $ingestSignal,
    ) {}

    /**
     * Verify, deduplicate, parse and ingest an inbound webhook.
     *
     * Returns HTTP status: 200 (ok), 200 (duplicate, already processed), 403 (bad signature).
     */
    public function execute(WebhookRoute $webhookRoute, Request $request): int
    {
        /** @var Integration|null $integration */
        $integration = $webhookRoute->integration;

        if (! $integration) {
            return Response::HTTP_NOT_FOUND;
        }

        $rawBody = $request->getContent();
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        // Layer 1: HMAC signature verification (delegated to driver)
        $driver = $this->manager->driver($integration->getAttribute('driver'));

        if ($webhookRoute->signing_secret) {
            $isValid = $driver->verifyWebhookSignature(
                rawBody: $rawBody,
                headers: $headers,
                secret: (string) $webhookRoute->signing_secret,
            );

            if (! $isValid) {
                Log::warning('HandleInboundWebhookAction: invalid signature', [
                    'integration_id' => $integration->getKey(),
                    'driver' => $integration->getAttribute('driver'),
                    'slug' => $webhookRoute->slug,
                ]);

                return Response::HTTP_FORBIDDEN;
            }
        }

        // Layer 2: Idempotency check (delivery ID from service-specific headers)
        $deliveryId = $headers['x-delivery-id']           // generic
            ?? $headers['x-github-delivery']              // GitHub
            ?? $headers['x-shopify-webhook-id']           // Shopify
            ?? $headers['x-gitlab-event-uuid']            // GitLab
            ?? $headers['x-zendesk-webhook-id']           // Zendesk
            ?? $headers['x-asana-request-id']             // Asana
            ?? $headers['x-calendly-webhook-id']          // Calendly
            ?? null;

        if ($deliveryId && $this->verifier->isAlreadyProcessed((string) $deliveryId)) {
            Log::info('HandleInboundWebhookAction: duplicate delivery, skipping', [
                'delivery_id' => $deliveryId,
            ]);

            return Response::HTTP_OK;
        }

        // Layer 3: Parse payload into signals and ingest
        $payload = $request->json()->all() ?: $request->all();
        $signals = $driver->parseWebhookPayload($payload, $headers);

        // Fallback dedup: derive key from the first signal's source_id (scoped to integration)
        // Covers services that have no delivery ID header (Typeform, Segment, Attio, Freshdesk, etc.)
        if (! $deliveryId && ! empty($signals)) {
            $firstSourceId = $signals[0]['source_id'] ?? null;
            if ($firstSourceId) {
                $deliveryId = $integration->getKey().':'.$firstSourceId;
            }
        }

        if ($deliveryId && $this->verifier->isAlreadyProcessed((string) $deliveryId)) {
            Log::info('HandleInboundWebhookAction: duplicate (payload-derived), skipping', [
                'delivery_id' => $deliveryId,
            ]);

            return Response::HTTP_OK;
        }

        $integrationDriver = $integration->getAttribute('driver');
        $teamId = $integration->getAttribute('team_id');

        foreach ($signals as $signalData) {
            try {
                $this->ingestSignal->execute(
                    sourceType: $signalData['source_type'] ?? $integrationDriver,
                    sourceIdentifier: $signalData['source_id'] ?? $webhookRoute->slug,
                    payload: $signalData['payload'] ?? $payload,
                    tags: $signalData['tags'] ?? ['integration', $integrationDriver],
                    sourceNativeId: $signalData['source_id'] ?? null,
                    teamId: $teamId,
                );
            } catch (\Throwable $e) {
                Log::error('HandleInboundWebhookAction: signal ingest failed', [
                    'error' => $e->getMessage(),
                    'integration_id' => $integration->getKey(),
                ]);
            }
        }

        // Mark delivery as processed for idempotency
        if ($deliveryId) {
            $this->verifier->markAsProcessed((string) $deliveryId);
        }

        return Response::HTTP_OK;
    }
}
