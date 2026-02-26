<?php

namespace App\Domain\Integration\Contracts;

use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;

interface IntegrationDriverInterface
{
    /**
     * Unique slug for this driver, e.g. 'slack', 'github', 'stripe'.
     */
    public function key(): string;

    /**
     * Human-readable label, e.g. 'Slack', 'GitHub'.
     */
    public function label(): string;

    /**
     * Short description shown in the integrations gallery.
     */
    public function description(): string;

    /**
     * Authentication type required by this driver.
     */
    public function authType(): AuthType;

    /**
     * Credential fields required by this driver.
     * Format: ['field_name' => ['type' => 'string', 'required' => true, 'label' => '...']]
     */
    public function credentialSchema(): array;

    /**
     * Validate the given credentials without persisting them.
     * Should make a lightweight test API call where possible.
     */
    public function validateCredentials(array $credentials): bool;

    /**
     * Perform a health-check against the integration's live credentials.
     */
    public function ping(Integration $integration): HealthResult;

    /**
     * Triggers this integration can produce (inbound events).
     *
     * @return TriggerDefinition[]
     */
    public function triggers(): array;

    /**
     * Actions this integration can perform (outbound operations).
     *
     * @return ActionDefinition[]
     */
    public function actions(): array;

    /**
     * Polling interval in seconds. Return 0 for webhook-only integrations.
     */
    public function pollFrequency(): int;

    /**
     * Poll the integration for new events, returning normalized Signal data arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function poll(Integration $integration): array;

    /**
     * Whether this driver supports inbound webhooks.
     */
    public function supportsWebhooks(): bool;

    /**
     * Verify the HMAC signature of an inbound webhook request.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool;

    /**
     * Parse a verified webhook payload into normalized Signal data arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseWebhookPayload(array $payload, array $headers): array;

    /**
     * Execute a named action on this integration.
     */
    public function execute(Integration $integration, string $action, array $params): mixed;
}
