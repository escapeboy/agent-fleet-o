<?php

namespace App\Domain\Integration\Drivers\Datadog;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Datadog integration driver.
 *
 * Supports multi-region (datadoghq.com / datadoghq.eu / us3.datadoghq.com).
 * Webhook verification uses X-Datadog-Webhook-Secret plain header match.
 * Coexists with the legacy DatadogAlertWebhookController + DatadogAlertConnector.
 *
 * @deprecated DatadogAlertConnector (signal-only) — use this driver for new integrations.
 */
class DatadogIntegrationDriver implements IntegrationDriverInterface
{
    private const SITES = [
        'datadoghq.com',
        'datadoghq.eu',
        'us3.datadoghq.com',
        'us5.datadoghq.com',
        'ap1.datadoghq.com',
    ];

    public function key(): string
    {
        return 'datadog';
    }

    public function label(): string
    {
        return 'Datadog';
    }

    public function description(): string
    {
        return 'Receive Datadog monitor alerts, post events, mute monitors, and push custom metrics.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'api_key' => ['type' => 'password', 'required' => true,  'label' => 'API Key',
                'hint' => 'From Datadog → Organization Settings → API Keys'],
            'app_key' => ['type' => 'password', 'required' => true,  'label' => 'Application Key',
                'hint' => 'From Datadog → Organization Settings → Application Keys'],
            'site' => ['type' => 'select',   'required' => false, 'label' => 'Datadog Site',
                'options' => self::SITES, 'default' => 'datadoghq.com',
                'hint' => 'EU customers use datadoghq.eu'],
            'webhook_secret' => ['type' => 'password', 'required' => false, 'label' => 'Webhook Secret',
                'hint' => 'Set in Datadog Webhooks integration — sent as X-Datadog-Webhook-Secret'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $apiKey = $credentials['api_key'] ?? null;
        $appKey = $credentials['app_key'] ?? null;
        $site = $credentials['site'] ?? 'datadoghq.com';

        if (! $apiKey || ! $appKey) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'DD-API-KEY' => $apiKey,
                'DD-APPLICATION-KEY' => $appKey,
            ])->timeout(10)->get("https://api.{$site}/api/v1/validate");

            return $response->successful() && ($response->json('valid') === true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $apiKey = $integration->getCredentialSecret('api_key');
        $appKey = $integration->getCredentialSecret('app_key');
        $site = $integration->config['site'] ?? 'datadoghq.com';

        if (! $apiKey || ! $appKey) {
            return HealthResult::fail('API key or application key not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withHeaders([
                'DD-API-KEY' => $apiKey,
                'DD-APPLICATION-KEY' => $appKey,
            ])->timeout(10)->get("https://api.{$site}/api/v1/validate");
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful() && $response->json('valid')) {
                return HealthResult::ok($latency, "Connected to {$site}");
            }

            return HealthResult::fail($response->json('errors.0') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('alert_triggered', 'Alert Triggered', 'A Datadog monitor entered alert state.'),
            new TriggerDefinition('alert_recovered', 'Alert Recovered', 'A Datadog monitor recovered from alert state.'),
            new TriggerDefinition('alert_snapshot', 'Alert Snapshot', 'A Datadog alert with snapshot attachment.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('post_event', 'Post Event', 'Post a custom event to the Datadog event stream.', [
                'title' => ['type' => 'string', 'required' => true,  'label' => 'Event title'],
                'text' => ['type' => 'string', 'required' => true,  'label' => 'Event body'],
                'alert_type' => ['type' => 'string', 'required' => false, 'label' => 'Alert type: info|warning|error|success'],
                'tags' => ['type' => 'array',  'required' => false, 'label' => 'Tags (e.g. ["env:prod","service:api"])'],
            ]),
            new ActionDefinition('mute_monitor', 'Mute Monitor', 'Temporarily mute a Datadog monitor.', [
                'monitor_id' => ['type' => 'string', 'required' => true,  'label' => 'Monitor ID'],
                'end' => ['type' => 'string', 'required' => false, 'label' => 'Mute until (Unix timestamp or ISO 8601)'],
            ]),
            new ActionDefinition('create_downtime', 'Create Downtime', 'Schedule a maintenance window (suppress alerts).', [
                'monitor_id' => ['type' => 'string', 'required' => false, 'label' => 'Monitor ID (null = all)'],
                'start' => ['type' => 'string', 'required' => true,  'label' => 'Start time (Unix timestamp)'],
                'end' => ['type' => 'string', 'required' => true,  'label' => 'End time (Unix timestamp)'],
                'message' => ['type' => 'string', 'required' => false, 'label' => 'Downtime message'],
            ]),
            new ActionDefinition('send_metric', 'Send Metric', 'Push a custom gauge metric to Datadog.', [
                'metric' => ['type' => 'string', 'required' => true,  'label' => 'Metric name (e.g. fleetq.agent.runs)'],
                'value' => ['type' => 'number', 'required' => true,  'label' => 'Metric value'],
                'tags' => ['type' => 'array',  'required' => false, 'label' => 'Tags'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 60;
    }

    public function poll(Integration $integration): array
    {
        // Polling active monitors via /api/v1/monitor — returns triggered monitor states
        $apiKey = $integration->getCredentialSecret('api_key');
        $appKey = $integration->getCredentialSecret('app_key');
        $site = $integration->config['site'] ?? 'datadoghq.com';

        if (! $apiKey || ! $appKey) {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'DD-API-KEY' => $apiKey,
                'DD-APPLICATION-KEY' => $appKey,
            ])->timeout(15)->get("https://api.{$site}/api/v1/monitor", [
                'monitor_tags' => 'fleetq',
                'with_downtimes' => false,
            ]);

            if (! $response->successful()) {
                return [];
            }

            $signals = [];
            foreach ($response->json() ?? [] as $monitor) {
                $overallState = $monitor['overall_state'] ?? '';
                if (! in_array($overallState, ['Alert', 'Warn', 'No Data'], true)) {
                    continue;
                }
                $signals[] = [
                    'source_type' => 'datadog',
                    'source_id' => 'dd:'.$monitor['id'],
                    'payload' => $monitor,
                    'tags' => ['datadog', 'monitor', strtolower($overallState)],
                ];
            }

            return $signals;
        } catch (\Throwable) {
            return [];
        }
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * Datadog sends X-Datadog-Webhook-Secret as a plain string configured in the Datadog Webhooks integration.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $header = $headers['x-datadog-webhook-secret'] ?? '';

        return hash_equals($secret, $header);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $alertStatus = strtolower($payload['alert_transition'] ?? $payload['alert_status'] ?? 'triggered');
        $trigger = str_contains($alertStatus, 'recover') ? 'alert_recovered' : 'alert_triggered';

        return [
            [
                'source_type' => 'datadog',
                'source_id' => 'dd:'.($payload['alert_id'] ?? uniqid('dd_', true)),
                'payload' => $payload,
                'tags' => ['datadog', $trigger],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $apiKey = $integration->getCredentialSecret('api_key');
        $appKey = $integration->getCredentialSecret('app_key');
        $site = $integration->config['site'] ?? 'datadoghq.com';

        abort_unless($apiKey && $appKey, 422, 'Datadog credentials not configured.');

        $headers = ['DD-API-KEY' => $apiKey, 'DD-APPLICATION-KEY' => $appKey];
        $base = "https://api.{$site}";

        return match ($action) {
            'post_event' => Http::withHeaders($headers)->timeout(15)
                ->post("{$base}/api/v1/events", [
                    'title' => $params['title'],
                    'text' => $params['text'],
                    'alert_type' => $params['alert_type'] ?? 'info',
                    'tags' => $params['tags'] ?? [],
                ])->json(),

            'mute_monitor' => Http::withHeaders($headers)->timeout(15)
                ->post("{$base}/api/v1/monitor/{$params['monitor_id']}/mute", array_filter([
                    'end' => $params['end'] ?? null,
                ]))->json(),

            'create_downtime' => Http::withHeaders($headers)->timeout(15)
                ->post("{$base}/api/v1/downtime", array_filter([
                    'scope' => 'host:*',
                    'monitor_id' => $params['monitor_id'] ?? null,
                    'start' => (int) $params['start'],
                    'end' => (int) $params['end'],
                    'message' => $params['message'] ?? '',
                ]))->json(),

            'send_metric' => Http::withHeaders($headers)->timeout(15)
                ->post("{$base}/api/v2/series", [
                    'series' => [[
                        'metric' => $params['metric'],
                        'type' => 3, // gauge
                        'points' => [['timestamp' => time(), 'value' => (float) $params['value']]],
                        'tags' => $params['tags'] ?? [],
                    ]],
                ])->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
