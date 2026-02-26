<?php

namespace App\Domain\Integration\Drivers\Linear;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

class LinearIntegrationDriver implements IntegrationDriverInterface
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
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'api_key' => ['type' => 'string', 'required' => true, 'label' => 'API Key', 'hint' => 'linear.app → Settings → API → Personal API keys'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $key = $credentials['api_key'] ?? null;

        if (! $key) {
            return false;
        }

        try {
            $response = Http::withToken($key)
                ->timeout(10)
                ->post(self::API_URL, ['query' => '{ viewer { id name } }']);

            return $response->successful() && isset($response->json()['data']['viewer']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $key = $integration->getCredentialSecret('api_key');

        if (! $key) {
            return HealthResult::fail('No API key configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withToken($key)
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
        $key = $integration->getCredentialSecret('api_key');

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
}
