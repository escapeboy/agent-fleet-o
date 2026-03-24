<?php

namespace App\Domain\Integration\Drivers\Freshdesk;

use App\Domain\Integration\Concerns\ChecksIntegrationResponse;
use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Freshdesk integration driver.
 *
 * Customer support platform. Webhooks are sent via Freshdesk Automation rules.
 * No built-in signature — uses permissive verification.
 * Supports polling for recent tickets.
 */
class FreshdeskIntegrationDriver implements IntegrationDriverInterface
{
    use ChecksIntegrationResponse;

    public function key(): string
    {
        return 'freshdesk';
    }

    public function label(): string
    {
        return 'Freshdesk';
    }

    public function description(): string
    {
        return 'Manage support tickets, receive ticket events, and automate customer support workflows with Freshdesk.';
    }

    public function authType(): AuthType
    {
        return AuthType::OAuth2;
    }

    public function credentialSchema(): array
    {
        return [];
    }

    private function apiBase(Integration|array $source): string
    {
        $domain = $source instanceof Integration
            ? ($source->getCredentialSecret('subdomain') ?? $source->getCredentialSecret('domain') ?? $source->config['domain'] ?? '')
            : ($source['subdomain'] ?? $source['domain'] ?? '');

        $domain = rtrim((string) $domain, '/');

        return "https://{$domain}.freshdesk.com/api/v2";
    }

    private function bearerToken(Integration $integration): string
    {
        return (string) ($integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('api_key') ?? '');
    }

    public function validateCredentials(array $credentials): bool
    {
        $subdomain = $credentials['subdomain'] ?? $credentials['domain'] ?? null;
        $accessToken = $credentials['access_token'] ?? $credentials['api_key'] ?? null;

        if (! $subdomain || ! $accessToken) {
            return false;
        }

        try {
            $base = $this->apiBase($credentials);

            return Http::withToken($accessToken)
                ->timeout(10)
                ->get("{$base}/tickets?per_page=1")
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $this->bearerToken($integration);

        if (! $token) {
            return HealthResult::fail('Credentials not configured.');
        }

        $start = microtime(true);
        try {
            $base = $this->apiBase($integration);
            $response = Http::withToken($token)
                ->timeout(10)
                ->get("{$base}/tickets?per_page=1");
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('description') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('ticket_created', 'Ticket Created', 'A new support ticket was created in Freshdesk.'),
            new TriggerDefinition('ticket_updated', 'Ticket Updated', 'An existing ticket was updated in Freshdesk.'),
            new TriggerDefinition('ticket_resolved', 'Ticket Resolved', 'A ticket was marked as resolved.'),
            new TriggerDefinition('reply_created', 'Reply Created', 'An agent or customer added a reply to a ticket.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_ticket', 'Create Ticket', 'Create a new support ticket.', [
                'subject' => ['type' => 'string', 'required' => true, 'label' => 'Subject'],
                'description' => ['type' => 'string', 'required' => true, 'label' => 'Description'],
                'email' => ['type' => 'string', 'required' => true, 'label' => 'Requester email'],
                'priority' => ['type' => 'string', 'required' => false, 'label' => 'Priority: 1=low, 2=medium, 3=high, 4=urgent'],
            ]),
            new ActionDefinition('update_ticket', 'Update Ticket', 'Update ticket status or priority.', [
                'ticket_id' => ['type' => 'string', 'required' => true, 'label' => 'Ticket ID'],
                'status' => ['type' => 'string', 'required' => false, 'label' => 'Status: 2=open, 3=pending, 4=resolved, 5=closed'],
                'priority' => ['type' => 'string', 'required' => false, 'label' => 'Priority: 1=low, 2=medium, 3=high, 4=urgent'],
            ]),
            new ActionDefinition('reply_to_ticket', 'Reply to Ticket', 'Add an agent reply to a ticket.', [
                'ticket_id' => ['type' => 'string', 'required' => true, 'label' => 'Ticket ID'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Reply body (HTML supported)'],
            ]),
            new ActionDefinition('assign_ticket', 'Assign Ticket', 'Assign a ticket to an agent or group.', [
                'ticket_id' => ['type' => 'string', 'required' => true, 'label' => 'Ticket ID'],
                'responder_id' => ['type' => 'string', 'required' => false, 'label' => 'Agent ID'],
                'group_id' => ['type' => 'string', 'required' => false, 'label' => 'Group ID'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 300;
    }

    public function poll(Integration $integration): array
    {
        $token = $this->bearerToken($integration);
        $base = $this->apiBase($integration);

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get("{$base}/tickets", [
                    'order_type' => 'desc',
                    'per_page' => 25,
                ]);

            if (! $response->successful()) {
                return [];
            }

            return array_map(fn ($ticket) => [
                'source_type' => 'freshdesk',
                'source_id' => 'freshdesk:'.$ticket['id'],
                'payload' => $ticket,
                'tags' => ['freshdesk', 'ticket', 'status:'.$ticket['status']],
            ], $response->json() ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * Freshdesk webhooks have no built-in signature. Return true (permissive).
     * Use webhook URL secrets or IP restrictions in Freshdesk Automation rules.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return true;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $ticketId = $payload['freshdesk_webhook']['ticket_id']
            ?? $payload['ticket_id']
            ?? uniqid('fd_', true);

        $action = $payload['freshdesk_webhook']['ticket_action']
            ?? $payload['action']
            ?? 'ticket_created';

        $trigger = match ($action) {
            'ticket_created' => 'ticket_created',
            'ticket_updated' => 'ticket_updated',
            'reply_created', 'note_created' => 'reply_created',
            default => str_replace(' ', '_', strtolower($action)),
        };

        return [
            [
                'source_type' => 'freshdesk',
                'source_id' => 'freshdesk:'.$ticketId,
                'payload' => $payload,
                'tags' => ['freshdesk', $trigger],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $this->bearerToken($integration);
        $base = $this->apiBase($integration);
        $http = Http::withToken($token)->timeout(15);

        return match ($action) {
            'create_ticket' => $this->checked($http->post("{$base}/tickets", array_filter([
                'subject' => $params['subject'],
                'description' => $params['description'],
                'email' => $params['email'],
                'priority' => isset($params['priority']) ? (int) $params['priority'] : null,
                'status' => 2,
            ])))->json(),

            'update_ticket' => $this->checked($http->put("{$base}/tickets/{$params['ticket_id']}", array_filter([
                'status' => isset($params['status']) ? (int) $params['status'] : null,
                'priority' => isset($params['priority']) ? (int) $params['priority'] : null,
            ])))->json(),

            'reply_to_ticket' => $this->checked($http->post("{$base}/tickets/{$params['ticket_id']}/reply", [
                'body' => $params['body'],
            ]))->json(),

            'assign_ticket' => $this->checked($http->put("{$base}/tickets/{$params['ticket_id']}", array_filter([
                'responder_id' => isset($params['responder_id']) ? (int) $params['responder_id'] : null,
                'group_id' => isset($params['group_id']) ? (int) $params['group_id'] : null,
            ])))->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
