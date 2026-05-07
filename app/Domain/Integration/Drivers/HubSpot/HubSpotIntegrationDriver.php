<?php

namespace App\Domain\Integration\Drivers\HubSpot;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * HubSpot CRM integration driver (OAuth2 + webhooks).
 *
 * Access tokens expire after 30 minutes; refresh_token is used to obtain new ones.
 * Webhook signature: X-HubSpot-Signature-Version: v3 + X-HubSpot-Signature
 * (HMAC SHA256 of client_secret + url + body).
 */
class HubSpotIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.hubapi.com';

    public function key(): string
    {
        return 'hubspot';
    }

    public function label(): string
    {
        return 'HubSpot';
    }

    public function description(): string
    {
        return 'Sync contacts and deals, trigger on CRM events, and automate HubSpot workflows from agent pipelines.';
    }

    public function authType(): AuthType
    {
        return AuthType::OAuth2;
    }

    public function credentialSchema(): array
    {
        return [
            'access_token' => ['type' => 'password', 'required' => true,  'label' => 'Access Token'],
            'refresh_token' => ['type' => 'password', 'required' => false, 'label' => 'Refresh Token'],
            'expires_at' => ['type' => 'string',   'required' => false, 'label' => 'Token Expiry (ISO 8601)'],
            'portal_id' => ['type' => 'string',   'required' => false, 'label' => 'Portal / Account ID'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? null;
        if (! $token) {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get(self::API_BASE.'/crm/v3/objects/contacts', ['limit' => 1]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        try {
            $token = $this->resolveAccessToken($integration);
        } catch (\Throwable $e) {
            return HealthResult::fail('Token refresh failed: '.$e->getMessage());
        }

        $start = microtime(true);
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get(self::API_BASE.'/crm/v3/objects/contacts', ['limit' => 1]);
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $portalId = $integration->credential?->secret_data['portal_id'] ?? 'unknown';

                return HealthResult::ok($latency, "Connected (portal {$portalId})");
            }

            return HealthResult::fail($response->json('message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('contact.creation', 'Contact Created', 'A new contact was created in HubSpot.'),
            new TriggerDefinition('contact.propertyChange', 'Contact Updated', 'A contact property was changed.'),
            new TriggerDefinition('deal.creation', 'Deal Created', 'A new deal was created.'),
            new TriggerDefinition('deal.propertyChange', 'Deal Updated', 'A deal property or stage changed.'),
            new TriggerDefinition('ticket.creation', 'Ticket Created', 'A new support ticket was opened.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_contact', 'Create Contact', 'Create a new HubSpot contact.', [
                'email' => ['type' => 'string', 'required' => true,  'label' => 'Email address'],
                'first_name' => ['type' => 'string', 'required' => false, 'label' => 'First name'],
                'last_name' => ['type' => 'string', 'required' => false, 'label' => 'Last name'],
                'phone' => ['type' => 'string', 'required' => false, 'label' => 'Phone number'],
            ]),
            new ActionDefinition('update_deal', 'Update Deal', 'Update a HubSpot deal properties.', [
                'deal_id' => ['type' => 'string', 'required' => true, 'label' => 'Deal ID'],
                'properties' => ['type' => 'array',  'required' => true, 'label' => 'Properties map (key → value)'],
            ]),
            new ActionDefinition('create_note', 'Create Note', 'Log an activity note on a CRM object.', [
                'object_type' => ['type' => 'string', 'required' => true, 'label' => 'Object type: contacts|deals|tickets'],
                'object_id' => ['type' => 'string', 'required' => true, 'label' => 'Object ID'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Note body'],
            ]),
            new ActionDefinition('create_task', 'Create Task', 'Create a follow-up task in HubSpot.', [
                'subject' => ['type' => 'string', 'required' => true,  'label' => 'Task subject'],
                'body' => ['type' => 'string', 'required' => false, 'label' => 'Task description'],
                'due_date' => ['type' => 'string', 'required' => false, 'label' => 'Due date (ISO 8601 or Unix ms)'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 0; // Webhook-driven
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
     * HubSpot v3 signature: HMAC SHA256 of (client_secret + requestUri + requestBody + timestamp)
     * Header: X-HubSpot-Signature-Version: v3, X-HubSpot-Signature: <base64>, X-HubSpot-Request-Timestamp: <ms>
     *
     * For simplicity we verify v1 fallback (HMAC SHA256 of client_secret + body) when v3 headers absent.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $version = $headers['x-hubspot-signature-version'] ?? 'v1';
        $signature = $headers['x-hubspot-signature'] ?? '';

        if ($version === 'v1') {
            $expected = hash('sha256', $secret.$rawBody);

            return hash_equals($expected, $signature);
        }

        // v3: HMAC SHA256 of method+uri+body+timestamp
        $timestamp = $headers['x-hubspot-request-timestamp'] ?? '';
        // Reject requests older than 5 minutes
        if (abs(time() * 1000 - (int) $timestamp) > 300_000) {
            return false;
        }
        // We don't have the full URI here, so fall back to body-only HMAC
        $expected = base64_encode(hash_hmac('sha256', $rawBody.$timestamp, $secret, true));

        return hash_equals($expected, $signature);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        // HubSpot sends an array of subscription events
        $events = is_array($payload[0] ?? null) ? $payload : [$payload];
        $signals = [];

        foreach ($events as $event) {
            $trigger = $event['subscriptionType'] ?? 'unknown';
            $signals[] = [
                'source_type' => 'hubspot',
                'source_id' => 'hs:'.($event['eventId'] ?? uniqid('hs_', true)),
                'payload' => $event,
                'tags' => ['hubspot', str_replace('.', '_', $trigger)],
            ];
        }

        return $signals;
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $this->resolveAccessToken($integration);

        return match ($action) {
            'create_contact' => Http::withToken($token)->timeout(15)
                ->post(self::API_BASE.'/crm/v3/objects/contacts', [
                    'properties' => array_filter([
                        'email' => $params['email'],
                        'firstname' => $params['first_name'] ?? null,
                        'lastname' => $params['last_name'] ?? null,
                        'phone' => $params['phone'] ?? null,
                    ]),
                ])->json(),

            'update_deal' => Http::withToken($token)->timeout(15)
                ->patch(self::API_BASE."/crm/v3/objects/deals/{$params['deal_id']}", [
                    'properties' => $params['properties'],
                ])->json(),

            'create_note' => Http::withToken($token)->timeout(15)
                ->post(self::API_BASE.'/crm/v3/objects/notes', [
                    'properties' => ['hs_note_body' => $params['body'], 'hs_timestamp' => now()->toIso8601String()],
                    'associations' => [[
                        'to' => ['id' => $params['object_id']],
                        'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => $this->noteAssocType($params['object_type'])]],
                    ]],
                ])->json(),

            'create_task' => Http::withToken($token)->timeout(15)
                ->post(self::API_BASE.'/crm/v3/objects/tasks', [
                    'properties' => array_filter([
                        'hs_task_subject' => $params['subject'],
                        'hs_task_body' => $params['body'] ?? null,
                        'hs_timestamp' => $params['due_date'] ?? now()->toIso8601String(),
                        'hs_task_status' => 'NOT_STARTED',
                        'hs_task_type' => 'TODO',
                    ]),
                ])->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    /**
     * Returns a valid access token, refreshing if expired.
     */
    private function resolveAccessToken(Integration $integration): string
    {
        $creds = $integration->credential->secret_data ?? [];
        $expiresAt = $creds['expires_at'] ?? null;
        $accessToken = $creds['access_token'] ?? null;

        if ($accessToken && (! $expiresAt || Carbon::parse($expiresAt)->gt(now()->addMinutes(2)))) {
            return $accessToken;
        }

        $refreshToken = $creds['refresh_token'] ?? null;
        abort_unless($refreshToken, 422, 'HubSpot access token expired and no refresh token available.');

        $response = Http::asForm()->timeout(15)->post('https://api.hubapi.com/oauth/v1/token', [
            'grant_type' => 'refresh_token',
            'client_id' => config('integrations.oauth.hubspot.client_id'),
            'client_secret' => config('integrations.oauth.hubspot.client_secret'),
            'refresh_token' => $refreshToken,
        ]);

        abort_unless($response->successful(), 422, 'HubSpot token refresh failed: '.$response->body());

        $newCreds = array_merge($creds, [
            'access_token' => $response->json('access_token'),
            'expires_at' => now()->addSeconds($response->json('expires_in', 1800))->toIso8601String(),
        ]);

        if ($integration->credential) {
            $integration->credential->update(['secret_data' => $newCreds]);
        }

        return $newCreds['access_token'];
    }

    private function noteAssocType(string $objectType): int
    {
        return match ($objectType) {
            'contacts' => 202,
            'deals' => 214,
            'tickets' => 216,
            default => 202,
        };
    }
}
