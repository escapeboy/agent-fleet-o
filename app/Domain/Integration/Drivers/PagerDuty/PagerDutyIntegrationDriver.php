<?php

namespace App\Domain\Integration\Drivers\PagerDuty;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * PagerDuty integration driver.
 *
 * Auth: REST API Key (Token token={key} header).
 * Webhooks: v3 format — X-PagerDuty-Signature: v1=HMAC-SHA256(secret, rawBody).
 *
 * @deprecated PagerDutyConnector (signal-only) — use this driver for new integrations.
 */
class PagerDutyIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.pagerduty.com';

    public function key(): string
    {
        return 'pagerduty';
    }

    public function label(): string
    {
        return 'PagerDuty';
    }

    public function description(): string
    {
        return 'Receive PagerDuty incident events, acknowledge and resolve incidents, and create on-call overrides.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'api_key'        => ['type' => 'password', 'required' => true,  'label' => 'REST API Key',
                                  'hint' => 'From PagerDuty → Integrations → API Access Keys'],
            'webhook_secret' => ['type' => 'password', 'required' => false, 'label' => 'Webhook Secret',
                                  'hint' => 'From PagerDuty → Integrations → Generic Webhooks (V3) → secret'],
            'service_id'     => ['type' => 'string',   'required' => false, 'label' => 'Default Service ID',
                                  'hint' => 'Used by create_incident action when no service_id is provided'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $apiKey = $credentials['api_key'] ?? null;
        if (! $apiKey) {
            return false;
        }

        try {
            $response = Http::withHeaders(['Authorization' => "Token token={$apiKey}"])
                ->timeout(10)
                ->get(self::API_BASE.'/users/me');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $apiKey = $integration->getCredentialSecret('api_key');
        if (! $apiKey) {
            return HealthResult::fail('No API key configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withHeaders(['Authorization' => "Token token={$apiKey}"])
                ->timeout(10)
                ->get(self::API_BASE.'/users/me');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $name = $response->json('user.name', 'PagerDuty');

                return HealthResult::ok($latency, "Connected as {$name}");
            }

            return HealthResult::fail($response->json('error.message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('incident.triggered',    'Incident Triggered',    'A new PagerDuty incident was created.'),
            new TriggerDefinition('incident.acknowledged', 'Incident Acknowledged', 'An incident was acknowledged.'),
            new TriggerDefinition('incident.resolved',     'Incident Resolved',     'An incident was resolved.'),
            new TriggerDefinition('incident.escalated',    'Incident Escalated',    'An incident was escalated.'),
            new TriggerDefinition('incident.reassigned',   'Incident Reassigned',   'An incident was reassigned.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('acknowledge_incident', 'Acknowledge Incident', 'Acknowledge a PagerDuty incident.', [
                'incident_id' => ['type' => 'string', 'required' => true, 'label' => 'Incident ID'],
                'from'        => ['type' => 'string', 'required' => true, 'label' => 'Acknowledger email'],
            ]),
            new ActionDefinition('resolve_incident', 'Resolve Incident', 'Resolve a PagerDuty incident with an optional note.', [
                'incident_id' => ['type' => 'string', 'required' => true,  'label' => 'Incident ID'],
                'from'        => ['type' => 'string', 'required' => true,  'label' => 'Resolver email'],
                'resolution'  => ['type' => 'string', 'required' => false, 'label' => 'Resolution note'],
            ]),
            new ActionDefinition('create_incident', 'Create Incident', 'Trigger a new PagerDuty incident.', [
                'title'      => ['type' => 'string', 'required' => true,  'label' => 'Incident title'],
                'service_id' => ['type' => 'string', 'required' => false, 'label' => 'Service ID (overrides default)'],
                'urgency'    => ['type' => 'string', 'required' => false, 'label' => 'Urgency: high|low'],
                'body'       => ['type' => 'string', 'required' => false, 'label' => 'Incident details'],
                'from'       => ['type' => 'string', 'required' => true,  'label' => 'Creator email'],
            ]),
            new ActionDefinition('add_note', 'Add Note', 'Add a timeline note to an incident.', [
                'incident_id' => ['type' => 'string', 'required' => true, 'label' => 'Incident ID'],
                'content'     => ['type' => 'string', 'required' => true, 'label' => 'Note text'],
                'from'        => ['type' => 'string', 'required' => true, 'label' => 'Author email'],
            ]),
            new ActionDefinition('create_override', 'Create Override', 'Create an on-call schedule override.', [
                'schedule_id' => ['type' => 'string', 'required' => true, 'label' => 'Schedule ID'],
                'user_id'     => ['type' => 'string', 'required' => true, 'label' => 'Override user ID'],
                'start'       => ['type' => 'string', 'required' => true, 'label' => 'Override start (ISO 8601)'],
                'end'         => ['type' => 'string', 'required' => true, 'label' => 'Override end (ISO 8601)'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 0; // Webhook-driven only
    }

    public function poll(Integration $integration): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * PagerDuty v3 webhook signature: X-PagerDuty-Signature: v1=HMAC-SHA256(secret, rawBody)
     * May contain multiple signatures for key rotation: "v1=hash1,v1=hash2"
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $header    = $headers['x-pagerduty-signature'] ?? '';
        $expected  = hash_hmac('sha256', $rawBody, $secret);
        $signatures = explode(',', $header);

        foreach ($signatures as $sig) {
            [$version, $hash] = array_pad(explode('=', trim($sig), 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $hash)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $signals = [];

        foreach ($payload['events'] ?? [$payload] as $event) {
            $eventType  = $event['event_type'] ?? 'unknown';
            $data       = $event['data'] ?? [];
            $incidentId = $data['id'] ?? uniqid('pd_', true);

            $signals[] = [
                'source_type' => 'pagerduty',
                'source_id'   => 'pd:'.$incidentId,
                'payload'     => $event,
                'tags'        => ['pagerduty', str_replace('.', '_', $eventType)],
            ];
        }

        return $signals;
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $apiKey = $integration->getCredentialSecret('api_key');
        abort_unless($apiKey, 422, 'PagerDuty API key not configured.');

        $headers = ['Authorization' => "Token token={$apiKey}"];

        return match ($action) {
            'acknowledge_incident' => Http::withHeaders($headers + ['From' => $params['from']])
                ->timeout(15)
                ->put(self::API_BASE."/incidents/{$params['incident_id']}", [
                    'incident' => ['type' => 'incident_reference', 'status' => 'acknowledged'],
                ])->json(),

            'resolve_incident' => Http::withHeaders($headers + ['From' => $params['from']])
                ->timeout(15)
                ->put(self::API_BASE."/incidents/{$params['incident_id']}", [
                    'incident' => array_filter([
                        'type'       => 'incident_reference',
                        'status'     => 'resolved',
                        'resolution' => $params['resolution'] ?? null,
                    ]),
                ])->json(),

            'create_incident' => Http::withHeaders($headers + ['From' => $params['from']])
                ->timeout(15)
                ->post(self::API_BASE.'/incidents', [
                    'incident' => array_filter([
                        'type'    => 'incident',
                        'title'   => $params['title'],
                        'service' => ['id' => $params['service_id'] ?? $integration->config['service_id'] ?? '', 'type' => 'service_reference'],
                        'urgency' => $params['urgency'] ?? 'high',
                        'body'    => isset($params['body']) ? ['type' => 'incident_body', 'details' => $params['body']] : null,
                    ]),
                ])->json(),

            'add_note' => Http::withHeaders($headers + ['From' => $params['from']])
                ->timeout(15)
                ->post(self::API_BASE."/incidents/{$params['incident_id']}/notes", [
                    'note' => ['content' => $params['content']],
                ])->json(),

            'create_override' => Http::withHeaders($headers)
                ->timeout(15)
                ->post(self::API_BASE."/schedules/{$params['schedule_id']}/overrides", [
                    'override' => [
                        'start' => $params['start'],
                        'end'   => $params['end'],
                        'user'  => ['id' => $params['user_id'], 'type' => 'user_reference'],
                    ],
                ])->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
