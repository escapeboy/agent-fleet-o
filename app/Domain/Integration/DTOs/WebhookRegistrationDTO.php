<?php

namespace App\Domain\Integration\DTOs;

/**
 * Result of registering a webhook at a provider via the API.
 *
 * Returned by IntegrationDriverInterface::registerWebhook() and stored
 * on the ConnectorSignalSubscription for future deregistration and expiry tracking.
 */
readonly class WebhookRegistrationDTO
{
    public function __construct(
        /** Provider-assigned webhook record ID (e.g. GitHub hook_id, Linear webhook ID). */
        public string $webhookId,
        /**
         * HMAC secret to use when verifying inbound payloads from this webhook.
         * Null for providers that do not sign webhook payloads (e.g. Jira Cloud REST webhooks).
         * Security is provided by the opaque subscription UUID embedded in the callback URL.
         */
        public ?string $webhookSecret,
        /**
         * Optional expiry (e.g. Jira dynamic webhooks expire after 30 days).
         * Null means the webhook is permanent.
         */
        public ?\DateTimeImmutable $expiresAt = null,
    ) {}
}
