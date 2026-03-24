<?php

namespace App\Domain\Integration\Drivers\GitHub;

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

class GitHubIntegrationDriver implements IntegrationDriverInterface, SubscribableConnectorInterface
{
    private const DEFAULT_BASE = 'https://api.github.com';

    public function key(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function description(): string
    {
        return 'Receive GitHub events (push, PR, issues, releases) and execute actions like creating issues and PRs.';
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

        // Convert web URL (https://github.mycompany.com) to API URL (/api/v3)
        // GitHub.com uses api.github.com, GHE uses {host}/api/v3
        if ($url === '' || $url === 'https://github.com') {
            return self::DEFAULT_BASE;
        }

        return $url.'/api/v3';
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? $credentials['token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            $response = Http::withToken($token)->timeout(10)->get($this->apiBase($credentials).'/user');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('token');

        if (! $token) {
            return HealthResult::fail('No token configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withToken((string) $token)->timeout(10)->get($this->apiBase($integration).'/user');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail("HTTP {$response->status()}");
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('push', 'Push', 'Code pushed to a branch.'),
            new TriggerDefinition('pull_request', 'Pull Request', 'PR opened, closed, or reviewed.'),
            new TriggerDefinition('issues', 'Issue', 'Issue opened, closed, or commented on.'),
            new TriggerDefinition('release', 'Release', 'New release published.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_issue', 'Create Issue', 'Create a new GitHub issue.', [
                'owner' => ['type' => 'string', 'required' => true],
                'repo' => ['type' => 'string', 'required' => true],
                'title' => ['type' => 'string', 'required' => true],
                'body' => ['type' => 'string', 'required' => false],
                'labels' => ['type' => 'array', 'required' => false],
            ]),
            new ActionDefinition('add_comment', 'Add Comment', 'Add a comment to an issue or PR.', [
                'owner' => ['type' => 'string', 'required' => true],
                'repo' => ['type' => 'string', 'required' => true],
                'issue_number' => ['type' => 'integer', 'required' => true],
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
        $signature = $headers['x-hub-signature-256'] ?? '';

        if (! $signature) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $event = $headers['x-github-event'] ?? 'unknown';
        $delivery = $headers['x-github-delivery'] ?? null;

        return [
            [
                'source_type' => 'github',
                'source_id' => $delivery ?? uniqid('gh_', true),
                'payload' => array_merge($payload, ['github_event' => $event]),
                'tags' => ['github', $event],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('token');
        $apiBase = $this->apiBase($integration);

        return match ($action) {
            'create_issue' => $this->createIssue($token, $params, $apiBase),
            'add_comment' => $this->addComment($token, $params, $apiBase),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function createIssue(?string $token, array $params, string $apiBase): array
    {
        $response = Http::withToken((string) $token)
            ->post("{$apiBase}/repos/{$params['owner']}/{$params['repo']}/issues", array_filter([
                'title' => $params['title'],
                'body' => $params['body'] ?? null,
                'labels' => $params['labels'] ?? null,
            ]));

        return $response->json();
    }

    private function addComment(?string $token, array $params, string $apiBase): array
    {
        $response = Http::withToken((string) $token)
            ->post("{$apiBase}/repos/{$params['owner']}/{$params['repo']}/issues/{$params['issue_number']}/comments", [
                'body' => $params['body'],
            ]);

        return $response->json();
    }

    // -----------------------------------------------------------------------
    // SubscribableConnectorInterface
    // -----------------------------------------------------------------------

    public function registerWebhook(Integration $integration, array $filterConfig, string $callbackUrl): WebhookRegistrationDTO
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('token');
        $repo = $filterConfig['repo'] ?? null;

        if (! $repo) {
            throw new \InvalidArgumentException('GitHub subscription requires filter_config.repo (e.g. "owner/repo").');
        }

        $secret = Str::random(40);

        $events = $this->resolveWebhookEvents($filterConfig);

        $response = Http::withToken((string) $token)
            ->timeout(15)
            ->post($this->apiBase($integration)."/repos/{$repo}/hooks", [
                'name' => 'web',
                'active' => true,
                'events' => $events,
                'config' => [
                    'url' => $callbackUrl,
                    'content_type' => 'json',
                    'secret' => $secret,
                    'insecure_ssl' => '0',
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("GitHub webhook registration failed for {$repo}: HTTP {$response->status()} — {$response->body()}");
        }

        return new WebhookRegistrationDTO(
            webhookId: (string) $response->json('id'),
            webhookSecret: $secret,
        );
    }

    public function deregisterWebhook(Integration $integration, string $webhookId, array $filterConfig): void
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('token');
        $repo = $filterConfig['repo'] ?? null;

        if (! $repo) {
            return;
        }

        Http::withToken((string) $token)
            ->timeout(15)
            ->delete($this->apiBase($integration)."/repos/{$repo}/hooks/{$webhookId}");
    }

    public function verifySubscriptionSignature(string $rawBody, array $headers, string $webhookSecret): bool
    {
        $signature = $headers['x-hub-signature-256'] ?? '';

        if (! $signature) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $webhookSecret), $signature);
    }

    public function mapPayloadToSignalDTO(array $payload, array $headers, array $filterConfig): ?SignalDTO
    {
        $event = $headers['x-github-event'] ?? 'unknown';
        $repo = $payload['repository']['full_name'] ?? ($filterConfig['repo'] ?? 'unknown');

        return match ($event) {
            'issues' => $this->mapIssueEvent($payload, $repo, $filterConfig),
            'pull_request' => $this->mapPullRequestEvent($payload, $repo, $filterConfig),
            'push' => $this->mapPushEvent($payload, $repo, $filterConfig),
            'workflow_run' => $this->mapWorkflowRunEvent($payload, $repo),
            'release' => $this->mapReleaseEvent($payload, $repo),
            default => null,
        };
    }

    private function mapIssueEvent(array $payload, string $repo, array $filterConfig): ?SignalDTO
    {
        $action = $payload['action'] ?? null;
        $filterActions = $filterConfig['filter_actions'] ?? [];
        $filterLabels = $filterConfig['filter_labels'] ?? [];

        if ($filterActions && ! in_array($action, $filterActions, true)) {
            return null;
        }

        if ($filterLabels) {
            $issueLabels = array_column($payload['issue']['labels'] ?? [], 'name');
            if (! array_intersect($filterLabels, $issueLabels)) {
                return null;
            }
        }

        $issueNumber = $payload['issue']['number'] ?? null;
        $repoNodeId = $payload['repository']['node_id'] ?? null;
        $issueNodeId = $payload['issue']['node_id'] ?? null;

        return new SignalDTO(
            sourceIdentifier: "{$repo}#{$issueNumber}",
            sourceNativeId: $repoNodeId && $issueNodeId ? "issues.{$action}.{$repoNodeId}.{$issueNodeId}" : null,
            payload: array_merge($payload, ['github_event' => 'issues']),
            tags: ['github', 'issues', $action ?? 'unknown'],
        );
    }

    private function mapPullRequestEvent(array $payload, string $repo, array $filterConfig): ?SignalDTO
    {
        $action = $payload['action'] ?? null;
        $filterActions = $filterConfig['filter_actions'] ?? [];

        if ($filterActions && ! in_array($action, $filterActions, true)) {
            return null;
        }

        $prNumber = $payload['pull_request']['number'] ?? null;

        return new SignalDTO(
            sourceIdentifier: "{$repo}#PR-{$prNumber}",
            sourceNativeId: null,
            payload: array_merge($payload, ['github_event' => 'pull_request']),
            tags: ['github', 'pull_request', $action ?? 'unknown'],
        );
    }

    private function mapPushEvent(array $payload, string $repo, array $filterConfig): ?SignalDTO
    {
        $ref = $payload['ref'] ?? null;
        $filterBranches = $filterConfig['filter_branches'] ?? [];

        if ($filterBranches && $ref) {
            $branch = str_replace('refs/heads/', '', $ref);
            if (! in_array($branch, $filterBranches, true)) {
                return null;
            }
        }

        $afterSha = $payload['after'] ?? null;

        return new SignalDTO(
            sourceIdentifier: "{$repo}:{$ref}",
            sourceNativeId: $afterSha ? "push.{$afterSha}" : null,
            payload: array_merge($payload, ['github_event' => 'push']),
            tags: ['github', 'push'],
        );
    }

    private function mapWorkflowRunEvent(array $payload, string $repo): ?SignalDTO
    {
        $action = $payload['action'] ?? null;

        if ($action !== 'completed') {
            return null;
        }

        $runId = $payload['workflow_run']['id'] ?? null;
        $conclusion = $payload['workflow_run']['conclusion'] ?? null;

        return new SignalDTO(
            sourceIdentifier: "{$repo}/workflow-run-{$runId}",
            sourceNativeId: null,
            payload: array_merge($payload, ['github_event' => 'workflow_run']),
            tags: array_filter(['github', 'workflow_run', $conclusion]),
        );
    }

    private function mapReleaseEvent(array $payload, string $repo): ?SignalDTO
    {
        $action = $payload['action'] ?? null;

        if ($action !== 'published') {
            return null;
        }

        $tagName = $payload['release']['tag_name'] ?? null;

        return new SignalDTO(
            sourceIdentifier: "{$repo}@{$tagName}",
            sourceNativeId: null,
            payload: array_merge($payload, ['github_event' => 'release']),
            tags: ['github', 'release'],
        );
    }

    /**
     * @param  array<string, mixed>  $filterConfig
     * @return string[]
     */
    private function resolveWebhookEvents(array $filterConfig): array
    {
        $eventTypes = $filterConfig['event_types'] ?? [];

        if ($eventTypes) {
            return $eventTypes;
        }

        // Default: subscribe to all supported event types.
        return ['issues', 'pull_request', 'push', 'workflow_run', 'release'];
    }
}
