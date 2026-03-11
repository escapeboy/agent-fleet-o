<?php

namespace App\Domain\Integration\Contracts;

use App\Domain\Integration\DTOs\WebhookRegistrationDTO;
use App\Domain\Integration\Models\Integration;
use App\Domain\Signal\DTOs\SignalDTO;

/**
 * Optional interface for integration drivers that support per-subscription
 * webhook management and signal ingestion via ConnectorSignalSubscriptions.
 *
 * Implement this interface alongside IntegrationDriverInterface to enable:
 *   - Programmatic webhook registration at the provider (auto-triggered on subscription create)
 *   - Programmatic webhook deregistration (auto-triggered on subscription delete)
 *   - Payload-to-signal mapping with per-subscription filter_config
 *
 * Drivers that do NOT implement this interface fall back to the existing
 * HMAC webhook path (SignalConnectorSetting / PerTeamSignalWebhookController).
 *
 * Example drivers that implement this: GitHub (OAuth App), Linear, Jira.
 */
interface SubscribableConnectorInterface
{
    /**
     * Register a webhook at the provider for a new ConnectorSignalSubscription.
     *
     * Called by RegisterSubscriptionWebhookJob after the subscription is saved.
     * The returned DTO is persisted on the subscription (webhook_id, webhook_secret,
     * webhook_expires_at).
     *
     * @param  array<string, mixed>  $filterConfig  From ConnectorSignalSubscription::$filter_config
     * @param  string  $callbackUrl  The URL the provider should POST events to
     */
    public function registerWebhook(Integration $integration, array $filterConfig, string $callbackUrl): WebhookRegistrationDTO;

    /**
     * Deregister a webhook at the provider.
     *
     * Called by DeregisterSubscriptionWebhookJob when a subscription is deleted.
     * Must be idempotent: if the webhook no longer exists at the provider, return silently.
     *
     * @param  array<string, mixed>  $filterConfig  From ConnectorSignalSubscription::$filter_config
     */
    public function deregisterWebhook(Integration $integration, string $webhookId, array $filterConfig): void;

    /**
     * Map a verified inbound webhook payload to a normalized SignalDTO.
     *
     * Called by IntegrationSignalBridge for each active subscription matching the driver.
     * Apply filter_config (e.g. skip events that don't match the subscribed repo,
     * label filter, or branch filter).
     *
     * Return null to skip ingestion for this payload (driver-level filtering applied).
     *
     * @param  array<string, mixed>  $payload  Parsed JSON body
     * @param  array<string, mixed>  $headers  Request headers (lowercased keys)
     * @param  array<string, mixed>  $filterConfig  From ConnectorSignalSubscription::$filter_config
     */
    public function mapPayloadToSignalDTO(array $payload, array $headers, array $filterConfig): ?SignalDTO;

    /**
     * Verify the HMAC signature on a subscription webhook request.
     *
     * Uses the per-subscription webhook_secret (not the team-wide SignalConnectorSetting secret).
     * Signature verification logic is driver-specific (e.g. GitHub uses sha256=HMAC).
     */
    public function verifySubscriptionSignature(string $rawBody, array $headers, string $webhookSecret): bool;
}
