<?php

namespace App\Domain\Integration\Drivers\GitLab;

use App\Domain\Integration\Concerns\ChecksIntegrationResponse;
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

/**
 * GitLab integration driver.
 *
 * Supports gitlab.com and self-hosted instances.
 * Signature: x-gitlab-token header — plain token comparison (NOT HMAC).
 * Implements SubscribableConnectorInterface for programmatic webhook registration.
 */
class GitLabIntegrationDriver implements IntegrationDriverInterface, SubscribableConnectorInterface
{
    use ChecksIntegrationResponse;

    private const DEFAULT_BASE = 'https://gitlab.com';

    public function key(): string
    {
        return 'gitlab';
    }

    public function label(): string
    {
        return 'GitLab';
    }

    public function description(): string
    {
        return 'Receive GitLab push, merge request, issue, and pipeline events. Supports gitlab.com and self-hosted.';
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
        $url = $source instanceof Integration
            ? ($source->getCredentialSecret('base_url') ?? '')
            : ($source['base_url'] ?? '');

        $url = rtrim((string) $url, '/');

        return ($url !== '' ? $url : self::DEFAULT_BASE).'/api/v4';
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? $credentials['token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            return Http::withToken($token)
                ->timeout(10)
                ->get($this->apiBase($credentials).'/user')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('token');

        if (! $token) {
            return HealthResult::fail('Access token not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get($this->apiBase($integration).'/user');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('push', 'Push', 'Code pushed to a GitLab repository branch.'),
            new TriggerDefinition('merge_request', 'Merge Request', 'A merge request was opened, merged, or closed.'),
            new TriggerDefinition('issues', 'Issue', 'An issue was opened, closed, or updated.'),
            new TriggerDefinition('pipeline', 'Pipeline', 'A CI/CD pipeline status changed.'),
            new TriggerDefinition('release', 'Release', 'A release was created or updated.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_issue', 'Create Issue', 'Create a new issue in a GitLab project.', [
                'project_id' => ['type' => 'string', 'required' => true, 'label' => 'Project ID or path (e.g. owner/repo)'],
                'title' => ['type' => 'string', 'required' => true, 'label' => 'Issue title'],
                'description' => ['type' => 'string', 'required' => false, 'label' => 'Issue description'],
                'labels' => ['type' => 'string', 'required' => false, 'label' => 'Labels (comma-separated)'],
            ]),
            new ActionDefinition('add_comment', 'Add Comment', 'Add a comment to an issue or merge request.', [
                'project_id' => ['type' => 'string', 'required' => true, 'label' => 'Project ID or path'],
                'iid' => ['type' => 'string', 'required' => true, 'label' => 'Issue or MR internal ID'],
                'type' => ['type' => 'string', 'required' => false, 'label' => 'Type: issues or merge_requests'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Comment body'],
            ]),
            new ActionDefinition('create_merge_request', 'Create Merge Request', 'Create a merge request.', [
                'project_id' => ['type' => 'string', 'required' => true, 'label' => 'Project ID or path'],
                'title' => ['type' => 'string', 'required' => true, 'label' => 'MR title'],
                'source_branch' => ['type' => 'string', 'required' => true, 'label' => 'Source branch'],
                'target_branch' => ['type' => 'string', 'required' => true, 'label' => 'Target branch'],
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

    /**
     * GitLab signature: x-gitlab-token header — plain token comparison, not HMAC.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $token = $headers['x-gitlab-token'] ?? '';

        if ($token === '') {
            return false;
        }

        return hash_equals($secret, $token);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $objectKind = $payload['object_kind'] ?? 'push';
        $projectId = $payload['project']['id'] ?? $payload['project_id'] ?? 'unknown';
        $eventId = $payload['object_attributes']['id']
            ?? $payload['checkout_sha']
            ?? uniqid('gitlab_', true);

        return [
            [
                'source_type' => 'gitlab',
                'source_id' => "gitlab:{$projectId}:{$eventId}",
                'payload' => $payload,
                'tags' => ['gitlab', $objectKind],
            ],
        ];
    }

    // SubscribableConnectorInterface

    public function registerWebhook(Integration $integration, array $filterConfig, string $callbackUrl): WebhookRegistrationDTO
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('token');
        $projectId = rawurlencode($filterConfig['project_id'] ?? '');
        $secret = Str::random(40);

        $response = Http::withToken($token)
            ->timeout(15)
            ->post($this->apiBase($integration)."/projects/{$projectId}/hooks", [
                'url' => $callbackUrl,
                'token' => $secret,
                'push_events' => true,
                'merge_requests_events' => true,
                'issues_events' => true,
                'pipeline_events' => true,
                'releases_events' => true,
            ]);

        $response->throw();

        return new WebhookRegistrationDTO(
            webhookId: (string) $response->json('id'),
            webhookSecret: $secret,
        );
    }

    public function deregisterWebhook(Integration $integration, string $webhookId, array $filterConfig): void
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('token');
        $projectId = rawurlencode($filterConfig['project_id'] ?? '');

        Http::withToken($token)
            ->timeout(15)
            ->delete($this->apiBase($integration)."/projects/{$projectId}/hooks/{$webhookId}");
    }

    public function verifySubscriptionSignature(string $rawBody, array $headers, string $webhookSecret): bool
    {
        return $this->verifyWebhookSignature($rawBody, $headers, $webhookSecret);
    }

    public function mapPayloadToSignalDTO(array $payload, array $headers, array $filterConfig): ?SignalDTO
    {
        $objectKind = $payload['object_kind'] ?? 'push';
        $projectId = $payload['project']['id'] ?? $payload['project_id'] ?? null;
        $eventId = $payload['object_attributes']['id']
            ?? $payload['checkout_sha']
            ?? uniqid('gitlab_', true);

        return new SignalDTO(
            sourceIdentifier: "gitlab:{$projectId}:{$eventId}",
            sourceNativeId: "gitlab.{$objectKind}.{$eventId}",
            payload: $payload,
            tags: ['gitlab', $objectKind],
        );
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('token');
        $base = $this->apiBase($integration);
        $projectId = rawurlencode($params['project_id'] ?? '');

        abort_unless($token, 422, 'GitLab access token not configured.');

        return match ($action) {
            'create_issue' => $this->checked(Http::withToken($token)->timeout(15)
                ->post("{$base}/projects/{$projectId}/issues", array_filter([
                    'title' => $params['title'],
                    'description' => $params['description'] ?? null,
                    'labels' => $params['labels'] ?? null,
                ])))->json(),

            'add_comment' => $this->checked(Http::withToken($token)->timeout(15)
                ->post("{$base}/projects/{$projectId}/{$params['type']}/{$params['iid']}/notes", [
                    'body' => $params['body'],
                ]))->json(),

            'create_merge_request' => $this->checked(Http::withToken($token)->timeout(15)
                ->post("{$base}/projects/{$projectId}/merge_requests", [
                    'title' => $params['title'],
                    'source_branch' => $params['source_branch'],
                    'target_branch' => $params['target_branch'],
                ]))->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
