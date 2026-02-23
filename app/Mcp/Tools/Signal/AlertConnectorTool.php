<?php

namespace App\Mcp\Tools\Signal;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class AlertConnectorTool extends Tool
{
    protected string $name = 'alert_connector_manage';

    protected string $description = 'Manage alert connectors (Sentry, Datadog, PagerDuty). List supported drivers and get webhook setup instructions for connecting error tracking and incident management tools.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action to perform: list_drivers | get_setup_instructions')
                ->enum(['list_drivers', 'get_setup_instructions'])
                ->required(),
            'driver' => $schema->string()
                ->description('Alert driver for setup instructions: sentry | datadog | pagerduty')
                ->enum(['sentry', 'datadog', 'pagerduty']),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|string|in:list_drivers,get_setup_instructions',
            'driver' => 'nullable|string|in:sentry,datadog,pagerduty',
        ]);

        $action = $validated['action'];

        try {
            return match ($action) {
                'list_drivers' => $this->listDrivers(),
                'get_setup_instructions' => $this->getSetupInstructions($validated['driver'] ?? null),
            };
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    private function listDrivers(): Response
    {
        return Response::text(json_encode([
            'drivers' => [
                [
                    'driver' => 'sentry',
                    'name' => 'Sentry',
                    'type' => 'push_webhook',
                    'webhook_url' => url('/api/signals/sentry'),
                    'signature_header' => 'Sentry-Hook-Signature',
                    'signature_format' => 'hex (no prefix)',
                    'config_key' => 'services.sentry.client_secret',
                    'severity_levels' => ['critical', 'high', 'warning', 'info'],
                ],
                [
                    'driver' => 'datadog',
                    'name' => 'Datadog',
                    'type' => 'push_webhook',
                    'webhook_url' => url('/api/signals/datadog/{YOUR_SECRET_TOKEN}'),
                    'signature_header' => null,
                    'auth_method' => 'secret_in_url',
                    'config_key' => 'services.datadog.webhook_secret',
                    'severity_levels' => ['critical', 'high', 'warning', 'info'],
                ],
                [
                    'driver' => 'pagerduty',
                    'name' => 'PagerDuty',
                    'type' => 'push_webhook',
                    'webhook_url' => url('/api/signals/pagerduty'),
                    'signature_header' => 'X-PagerDuty-Signature',
                    'signature_format' => 'v1=<hex> (supports multiple for key rotation)',
                    'config_key' => 'services.pagerduty.webhook_secret',
                    'severity_levels' => ['critical', 'high', 'warning', 'info'],
                ],
            ],
        ]));
    }

    private function getSetupInstructions(?string $driver): Response
    {
        if (! $driver) {
            return Response::error('driver parameter required for get_setup_instructions');
        }

        $instructions = match ($driver) {
            'sentry' => [
                'webhook_url' => url('/api/signals/sentry'),
                'steps' => [
                    '1. Go to Sentry → Settings → Integrations → Create New Integration',
                    '2. Add a Webhook URL: '.url('/api/signals/sentry'),
                    '3. Copy the "Client Secret" from the integration page',
                    '4. Add to your .env: SENTRY_CLIENT_SECRET=<your_secret>',
                    '5. Enable "Send Alerts" and configure alert rules to use this webhook',
                    '6. Enable events: error, issue, event_alert, metric_alert',
                ],
                'env_var' => 'SENTRY_CLIENT_SECRET',
                'services_config' => "Add to config/services.php:\n'sentry' => ['client_secret' => env('SENTRY_CLIENT_SECRET')]",
                'note' => 'Sentry requires responses within 1 second. Processing happens asynchronously.',
            ],
            'datadog' => [
                'webhook_url' => url('/api/signals/datadog/{YOUR_SECRET_TOKEN}'),
                'steps' => [
                    '1. Generate a random secret token (e.g. openssl rand -hex 32)',
                    '2. Add to your .env: DATADOG_WEBHOOK_SECRET=<your_token>',
                    '3. Go to Datadog → Integrations → Webhooks → New',
                    '4. Set URL to: '.url('/api/signals/datadog/').'<your_token>',
                    '5. Configure the custom payload to include alert fields',
                    '6. Attach the webhook to your monitor alert rules',
                ],
                'env_var' => 'DATADOG_WEBHOOK_SECRET',
                'services_config' => "Add to config/services.php:\n'datadog' => ['webhook_secret' => env('DATADOG_WEBHOOK_SECRET')]",
                'recommended_payload' => [
                    'alert_id' => '$ALERT_ID',
                    'alert_title' => '$EVENT_TITLE',
                    'alert_status' => '$ALERT_STATUS',
                    'alert_type' => '$ALERT_TYPE',
                    'alert_transition' => '$ALERT_TRANSITION',
                    'priority' => '$PRIORITY',
                    'hostname' => '$HOSTNAME',
                    'tags' => '$TAGS',
                    'link' => '$LINK',
                    'event_message' => '$EVENT_MSG',
                ],
            ],
            'pagerduty' => [
                'webhook_url' => url('/api/signals/pagerduty'),
                'steps' => [
                    '1. Go to PagerDuty → Integrations → Webhook Subscriptions → New Webhook',
                    '2. Set Webhook URL to: '.url('/api/signals/pagerduty'),
                    '3. Select event types: incident.triggered, incident.acknowledged, incident.resolved',
                    '4. Copy the signing secret and add to .env: PAGERDUTY_WEBHOOK_SECRET=<secret>',
                    '5. Save the webhook subscription',
                ],
                'env_var' => 'PAGERDUTY_WEBHOOK_SECRET',
                'services_config' => "Add to config/services.php:\n'pagerduty' => ['webhook_secret' => env('PAGERDUTY_WEBHOOK_SECRET')]",
                'note' => 'PagerDuty v3 supports multiple signatures in X-PagerDuty-Signature header for key rotation.',
            ],
            default => throw new \InvalidArgumentException("Unknown driver: {$driver}"),
        };

        return Response::text(json_encode($instructions));
    }
}
