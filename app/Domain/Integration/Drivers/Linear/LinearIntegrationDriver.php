<?php

namespace App\Domain\Integration\Drivers\Linear;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\Contracts\SubscribableConnectorInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\DTOs\WebhookRegistrationDTO;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use App\Domain\Signal\DTOs\SignalDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LinearIntegrationDriver implements IntegrationDriverInterface, SubscribableConnectorInterface
{
    private const API_URL = 'https://api.linear.app/graphql';

    public function key(): string
    {
        return 'linear';
    }

    public function label(): string
    {
        return 'Linear';
    }

    public function description(): string
    {
        return 'Receive Linear issue events via webhooks and create/update issues from AI output.';
    }

    public function authType(): AuthType
    {
        return AuthType::OAuth2;
    }

    public function credentialSchema(): array
    {
        // OAuth2 — credentials are populated by OAuthCallbackAction, not from a form.
        return [];
    }

    public function validateCredentials(array $credentials): bool
    {
        // Validate either OAuth access_token or legacy api_key.
        $token = $credentials['access_token'] ?? $credentials['api_key'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->post(self::API_URL, ['query' => '{ viewer { id name } }']);

            return $response->successful() && isset($response->json()['data']['viewer']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $this->resolveToken($integration);

        if (! $token) {
            return HealthResult::fail('No access token configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->post(self::API_URL, ['query' => '{ viewer { id } }']);
            $latency = (int) ((microtime(true) - $start) * 1000);

            return ($response->successful() && isset($response->json()['data']['viewer']))
                ? HealthResult::ok($latency)
                : HealthResult::fail("HTTP {$response->status()}");
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('issue_created', 'Issue Created', 'A new Linear issue was created (webhook).'),
            new TriggerDefinition('issue_updated', 'Issue Updated', 'A Linear issue was updated (webhook).'),
            new TriggerDefinition('comment_created', 'Comment Created', 'A comment was added to an issue (webhook).'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_issue', 'Create Issue', 'Create a new Linear issue.', [
                'team_id' => ['type' => 'string', 'required' => true],
                'title' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string', 'required' => false],
            ]),
            new ActionDefinition('add_comment', 'Add Comment', 'Add a comment to a Linear issue.', [
                'issue_id' => ['type' => 'string', 'required' => true],
                'body' => ['type' => 'string', 'required' => true],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 0;
    }

    public function poll(Integration $integration): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $signature = $headers['linear-signature'] ?? '';

        if (! $signature) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $type = $payload['type'] ?? 'unknown';
        $deliveryId = $headers['linear-delivery'] ?? uniqid('linear_', true);

        return [
            [
                'source_type' => 'linear',
                'source_id' => $deliveryId,
                'payload' => $payload,
                'tags' => ['linear', $type],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $key = $this->resolveToken($integration);

        return match ($action) {
            'create_issue' => $this->graphql($key, '
                mutation CreateIssue($teamId: String!, $title: String!, $description: String) {
                    issueCreate(input: { teamId: $teamId, title: $title, description: $description }) {
                        success issue { id title url }
                    }
                }
            ', ['teamId' => $params['team_id'], 'title' => $params['title'], 'description' => $params['description'] ?? null]),

            'add_comment' => $this->graphql($key, '
                mutation AddComment($issueId: String!, $body: String!) {
                    commentCreate(input: { issueId: $issueId, body: $body }) {
                        success comment { id }
                    }
                }
            ', ['issueId' => $params['issue_id'], 'body' => $params['body']]),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function graphql(?string $key, string $query, array $variables = []): array
    {
        $response = Http::withToken((string) $key)
            ->post(self::API_URL, array_filter(['query' => $query, 'variables' => $variables]));

        return $response->json();
    }

    /**
     * Resolve bearer token — OAuth access_token preferred, api_key fallback for legacy integrations.
     */
    private function resolveToken(Integration $integration): ?string
    {
        return $integration->getCredentialSecret('access_token')
            ?? $integration->getCredentialSecret('api_key');
    }

    // -----------------------------------------------------------------------
    // SubscribableConnectorInterface
    // -----------------------------------------------------------------------

    public function registerWebhook(Integration $integration, array $filterConfig, string $callbackUrl): WebhookRegistrationDTO
    {
        $token = $this->resolveToken($integration);

        if (! $token) {
            throw new \RuntimeException('Linear integration has no access token.');
        }

        $secret = Str::random(40);

        $resourceTypes = $filterConfig['resource_types'] ?? ['Issue', 'Comment'];
        $teamId = $filterConfig['team_id'] ?? null;

        $input = array_filter([
            'url' => $callbackUrl,
            'secret' => $secret,
            'resourceTypes' => $resourceTypes,
            'teamId' => $teamId,
        ]);

        $result = $this->graphql($token, '
            mutation WebhookCreate($input: WebhookCreateInput!) {
                webhookCreate(input: $input) {
                    success
                    webhook { id enabled }
                }
            }
        ', ['input' => $input]);

        if (empty($result['data']['webhookCreate']['success'])) {
            $errors = json_encode($result['errors'] ?? $result);
            throw new \RuntimeException("Linear webhook registration failed: {$errors}");
        }

        return new WebhookRegistrationDTO(
            webhookId: (string) ($result['data']['webhookCreate']['webhook']['id'] ?? ''),
            webhookSecret: $secret,
        );
    }

    public function deregisterWebhook(Integration $integration, string $webhookId, array $filterConfig): void
    {
        $token = $this->resolveToken($integration);

        if (! $token) {
            return;
        }

        $this->graphql($token, '
            mutation WebhookDelete($id: String!) {
                webhookDelete(id: $id) { success }
            }
        ', ['id' => $webhookId]);
    }

    public function verifySubscriptionSignature(string $rawBody, array $headers, string $webhookSecret): bool
    {
        $signature = $headers['linear-signature'] ?? '';

        if (! $signature) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $webhookSecret), $signature);
    }

    public function mapPayloadToSignalDTO(array $payload, array $headers, array $filterConfig): ?SignalDTO
    {
        $type = $payload['type'] ?? '';
        $action = $payload['action'] ?? '';

        // Only Issue and Comment events are mapped to signals
        if (! in_array($type, ['Issue', 'Comment'], true)) {
            return null;
        }

        // Filter by action
        $filterActions = $filterConfig['filter_actions'] ?? [];
        if ($filterActions && ! in_array($action, $filterActions, true)) {
            return null;
        }

        $data = $payload['data'] ?? [];
        $identifier = $data['identifier'] ?? $data['id'] ?? null;
        $teamName = $data['team']['name'] ?? null;

        // Filter by team name
        $filterTeams = $filterConfig['filter_teams'] ?? [];
        if ($filterTeams && $teamName && ! in_array($teamName, $filterTeams, true)) {
            return null;
        }

        $labels = array_column($data['labels'] ?? [], 'name');
        $deliveryId = $headers['linear-delivery'] ?? null;

        $tags = array_values(array_unique(
            array_merge(['linear', strtolower($type)], $labels),
        ));

        return new SignalDTO(
            sourceIdentifier: (string) ($identifier ?? ($deliveryId ?? uniqid('linear_', true))),
            sourceNativeId: $deliveryId ? "linear.{$type}.{$action}.{$deliveryId}" : null,
            payload: array_merge($payload, ['linear_event_type' => $type]),
            tags: $tags,
        );
    }
}
