<?php

namespace App\Domain\Integration\Drivers\Make;

use App\Domain\Integration\Drivers\Concerns\WebhookTriggerDriver;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\TriggerDefinition;

/**
 * Make (Integromat) meta-integration driver.
 *
 * Bidirectional: FleetQ can receive events FROM Make scenarios (inbound webhook)
 * and trigger Make scenarios (outbound via custom webhook URL).
 *
 * No OAuth or API key required — webhook URLs are the only configuration.
 * Make scenarios must be active to receive outbound webhooks.
 */
class MakeIntegrationDriver extends WebhookTriggerDriver
{
    protected function webhookUrlConfigKey(): string
    {
        return 'make_webhook_url';
    }

    public function key(): string
    {
        return 'make';
    }

    public function label(): string
    {
        return 'Make';
    }

    public function description(): string
    {
        return 'Connect FleetQ to 1,500+ apps via Make (Integromat). Trigger scenarios from agents or receive Make data into workflows.';
    }

    public function credentialSchema(): array
    {
        return [
            'make_webhook_url' => [
                'type' => 'url',
                'required' => false,
                'label' => 'Make Webhook URL',
                'hint' => 'From Make → Webhooks → Custom webhook → copy URL. The scenario must be active to receive events.',
            ],
            'webhook_secret' => [
                'type' => 'password',
                'required' => false,
                'label' => 'Inbound Webhook Secret',
                'hint' => 'Set a shared secret in your Make scenario header X-Webhook-Secret to verify inbound requests.',
            ],
        ];
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('scenario_triggered', 'Scenario Triggered', 'A Make scenario sent data to FleetQ.'),
            new TriggerDefinition('data_received', 'Data Received', 'Make sent a generic data payload to FleetQ.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('trigger_scenario', 'Trigger Scenario', 'POST data to a Make custom webhook to trigger a scenario.', [
                'payload' => ['type' => 'array', 'required' => true, 'label' => 'Payload to send to Make scenario'],
            ]),
            new ActionDefinition('send_data', 'Send Data', 'Send structured data to a Make scenario via webhook.', [
                'data' => ['type' => 'array', 'required' => true, 'label' => 'Structured data map'],
            ]),
        ];
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [
            [
                'source_type' => 'make',
                'source_id' => 'make:'.(string) ($payload['id'] ?? uniqid('make_', true)),
                'payload' => $payload,
                'tags' => ['make', 'scenario_triggered'],
            ],
        ];
    }
}
