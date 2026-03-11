<?php

namespace App\Domain\Integration\Drivers\Zendesk;

use App\Domain\Integration\Concerns\ChecksIntegrationResponse;
use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Zendesk integration driver.
 *
 * Customer support platform. Basic auth with email/token format.
 * Webhook signature uses three headers: timestamp, nonce, and base64 HMAC-SHA256
 * of the concatenation "{timestamp}{nonce}{rawBody}".
 */
class ZendeskIntegrationDriver implements IntegrationDriverInterface
{
    use ChecksIntegrationResponse;

    public function key(): string
    {
        return 'zendesk';
    }

    public function label(): string
    {
        return 'Zendesk';
    }

    public function description(): string
    {
        return 'Manage support tickets, receive ticket events, and automate customer support workflows with Zendesk.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'subdomain' => ['type' => 'string', 'required' => true, 'label' => 'Subdomain',
                'hint' => 'yourcompany (from yourcompany.zendesk.com)'],
            'email' => ['type' => 'string', 'required' => true, 'label' => 'Agent Email'],
            'api_token' => ['type' => 'password', 'required' => true, 'label' => 'API Token',
                'hint' => 'Admin Center → Apps and Integrations → Zendesk API → Add API token'],
        ];
    }

    private function apiBase(Integration|array $source): string
    {
        $subdomain = $source instanceof Integration
            ? ($source->config['subdomain'] ?? $source->getCredentialSecret('subdomain') ?? '')
            : ($source['subdomain'] ?? '');

        return "https://{$subdomain}.zendesk.com/api/v2";
    }

    private function basicAuth(Integration|array $source): string
    {
        [$email, $token] = $source instanceof Integration
            ? [$source->getCredentialSecret('email'), $source->getCredentialSecret('api_token')]
            : [$source['email'] ?? '', $source['api_token'] ?? ''];

        return base64_encode("{$email}/token:{$token}");
    }

    public function validateCredentials(array $credentials): bool
    {
        if (empty($credentials['subdomain']) || empty($credentials['email']) || empty($credentials['api_token'])) {
            return false;
        }

        try {
            $base = "https://{$credentials['subdomain']}.zendesk.com/api/v2";
            $auth = base64_encode("{$credentials['email']}/token:{$credentials['api_token']}");

            return Http::withHeaders(['Authorization' => "Basic {$auth}"])
                ->timeout(10)
                ->get("{$base}/users/me.json")
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $base = $this->apiBase($integration);
        $auth = $this->basicAuth($integration);

        $start = microtime(true);
        try {
            $response = Http::withHeaders(['Authorization' => "Basic {$auth}"])
                ->timeout(10)
                ->get("{$base}/users/me.json");
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('error') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('ticket_created', 'Ticket Created', 'A new support ticket was created.'),
            new TriggerDefinition('ticket_updated', 'Ticket Updated', 'A ticket was updated.'),
            new TriggerDefinition('ticket_solved', 'Ticket Solved', 'A ticket was marked as solved.'),
            new TriggerDefinition('comment_created', 'Comment Created', 'A reply or internal note was added to a ticket.'),
            new TriggerDefinition('satisfaction_rating_created', 'CSAT Received', 'A customer satisfaction rating was submitted.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_ticket', 'Create Ticket', 'Create a new support ticket in Zendesk.', [
                'subject' => ['type' => 'string', 'required' => true, 'label' => 'Subject'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Description'],
                'requester_email' => ['type' => 'string', 'required' => true, 'label' => 'Requester email'],
                'priority' => ['type' => 'string', 'required' => false, 'label' => 'Priority: low|normal|high|urgent'],
            ]),
            new ActionDefinition('update_ticket', 'Update Ticket', 'Update ticket status, priority, or assignee.', [
                'ticket_id' => ['type' => 'string', 'required' => true, 'label' => 'Ticket ID'],
                'status' => ['type' => 'string', 'required' => false, 'label' => 'Status: open|pending|hold|solved|closed'],
                'priority' => ['type' => 'string', 'required' => false, 'label' => 'Priority: low|normal|high|urgent'],
                'assignee_email' => ['type' => 'string', 'required' => false, 'label' => 'Assignee email'],
            ]),
            new ActionDefinition('add_comment', 'Add Comment', 'Add a public reply or internal note.', [
                'ticket_id' => ['type' => 'string', 'required' => true, 'label' => 'Ticket ID'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Comment body'],
                'public' => ['type' => 'string', 'required' => false, 'label' => 'Public: true or false'],
            ]),
            new ActionDefinition('close_ticket', 'Close Ticket', 'Mark a ticket as closed.', [
                'ticket_id' => ['type' => 'string', 'required' => true, 'label' => 'Ticket ID'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 120;
    }

    public function poll(Integration $integration): array
    {
        $base = $this->apiBase($integration);
        $auth = $this->basicAuth($integration);

        try {
            $response = Http::withHeaders(['Authorization' => "Basic {$auth}"])
                ->timeout(15)
                ->get("{$base}/tickets.json", ['sort_by' => 'updated_at', 'sort_order' => 'desc', 'per_page' => 25]);

            if (! $response->successful()) {
                return [];
            }

            return array_map(fn ($ticket) => [
                'source_type' => 'zendesk',
                'source_id' => 'zendesk:'.$ticket['id'],
                'payload' => $ticket,
                'tags' => ['zendesk', 'ticket', $ticket['status'] ?? 'open'],
            ], $response->json('tickets') ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * Zendesk signature: x-zendesk-webhook-signature header — base64(HMAC-SHA256("{ts}{nonce}{rawBody}")).
     * Requires x-zendesk-webhook-signature-timestamp and x-zendesk-webhook-signature-nonce headers.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $ts = $headers['x-zendesk-webhook-signature-timestamp'] ?? '';
        $nonce = $headers['x-zendesk-webhook-signature-nonce'] ?? '';
        $sig = $headers['x-zendesk-webhook-signature'] ?? '';

        if (! $ts || ! $nonce || ! $sig) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $ts.$nonce.$rawBody, $secret, true));

        return hash_equals($expected, $sig);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $ticketId = $payload['ticket']['id']
            ?? $payload['ticketId']
            ?? uniqid('zd_', true);

        $event = $payload['type']
            ?? $headers['x-zendesk-webhook-event-type']
            ?? 'ticket.created';

        $trigger = match ($event) {
            'ticket.created' => 'ticket_created',
            'ticket.updated' => 'ticket_updated',
            'ticket.solved' => 'ticket_solved',
            'comment.created' => 'comment_created',
            'satisfaction_rating.created' => 'satisfaction_rating_created',
            default => str_replace('.', '_', $event),
        };

        return [
            [
                'source_type' => 'zendesk',
                'source_id' => 'zendesk:'.$ticketId,
                'payload' => $payload,
                'tags' => ['zendesk', $trigger],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $base = $this->apiBase($integration);
        $auth = $this->basicAuth($integration);
        $http = Http::withHeaders(['Authorization' => "Basic {$auth}"])->timeout(15);

        return match ($action) {
            'create_ticket' => $this->checked($http->post("{$base}/tickets.json", ['ticket' => array_filter([
                'subject' => $params['subject'],
                'comment' => ['body' => $params['body']],
                'requester' => ['email' => $params['requester_email']],
                'priority' => $params['priority'] ?? null,
            ])]))->json(),

            'update_ticket' => $this->checked($http->put("{$base}/tickets/{$params['ticket_id']}.json", [
                'ticket' => array_filter([
                    'status' => $params['status'] ?? null,
                    'priority' => $params['priority'] ?? null,
                    'assignee_email' => $params['assignee_email'] ?? null,
                ]),
            ]))->json(),

            'add_comment' => $this->checked($http->put("{$base}/tickets/{$params['ticket_id']}.json", [
                'ticket' => ['comment' => [
                    'body' => $params['body'],
                    'public' => ($params['public'] ?? 'true') === 'true',
                ]],
            ]))->json(),

            'close_ticket' => $this->checked($http->put("{$base}/tickets/{$params['ticket_id']}.json", [
                'ticket' => ['status' => 'closed'],
            ]))->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
