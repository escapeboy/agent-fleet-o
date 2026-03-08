<?php

namespace App\Domain\Integration\Drivers\Zapier;

use App\Domain\Integration\Drivers\Concerns\WebhookTriggerDriver;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\TriggerDefinition;

/**
 * Zapier meta-integration driver.
 *
 * Bidirectional: FleetQ can receive events FROM Zapier (inbound webhook)
 * and send events TO Zapier (outbound via catch hook URL).
 *
 * No OAuth or API key required — webhook URLs are the only configuration.
 */
class ZapierIntegrationDriver extends WebhookTriggerDriver
{
    protected function webhookUrlConfigKey(): string
    {
        return 'zapier_webhook_url';
    }

    public function key(): string
    {
        return 'zapier';
    }

    public function label(): string
    {
        return 'Zapier';
    }

    public function description(): string
    {
        return 'Connect FleetQ to 5,000+ apps via Zapier. Send agent output to any Zap, or trigger workflows when Zapier sends data.';
    }

    public function credentialSchema(): array
    {
        return [
            'zapier_webhook_url' => [
                'type' => 'url',
                'required' => false,
                'label' => 'Zapier Catch Hook URL',
                'hint' => 'From Zapier → "Webhooks by Zapier" → "Catch Hook" trigger → copy URL.',
            ],
            'webhook_secret' => [
                'type' => 'password',
                'required' => false,
                'label' => 'Inbound Webhook Secret',
                'hint' => 'Set a shared secret in your Zapier Webhooks action header X-Webhook-Secret to verify inbound requests.',
            ],
        ];
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('webhook_received', 'Webhook Received', 'Zapier sent a payload to FleetQ (any trigger type).'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('send_event', 'Send Event', 'POST agent output as an event to Zapier\'s Catch Hook URL.', [
                'payload' => ['type' => 'array', 'required' => true, 'label' => 'Event payload (key-value map)'],
            ]),
            new ActionDefinition('trigger_zap', 'Trigger Zap', 'Send a named event to Zapier with structured data.', [
                'event_name' => ['type' => 'string', 'required' => true,  'label' => 'Event name / type identifier'],
                'data' => ['type' => 'array',  'required' => false, 'label' => 'Event data payload'],
            ]),
        ];
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [
            [
                'source_type' => 'zapier',
                'source_id' => 'zap:'.(string) ($payload['id'] ?? uniqid('zap_', true)),
                'payload' => $payload,
                'tags' => ['zapier', 'webhook_received'],
            ],
        ];
    }
}
