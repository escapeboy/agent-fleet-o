<?php

namespace App\Domain\Integration\Drivers\GitHub;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

class GitHubIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.github.com';

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
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'token' => ['type' => 'string', 'required' => true, 'label' => 'Personal Access Token', 'hint' => 'github.com → Settings → Developer settings → Personal access tokens'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            $response = Http::withToken($token)->timeout(10)->get(self::API_BASE.'/user');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('token');

        if (! $token) {
            return HealthResult::fail('No token configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withToken($token)->timeout(10)->get(self::API_BASE.'/user');
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
        $token = $integration->getCredentialSecret('token');

        return match ($action) {
            'create_issue' => $this->createIssue($token, $params),
            'add_comment' => $this->addComment($token, $params),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function createIssue(?string $token, array $params): array
    {
        $response = Http::withToken((string) $token)
            ->post(self::API_BASE."/repos/{$params['owner']}/{$params['repo']}/issues", array_filter([
                'title' => $params['title'],
                'body' => $params['body'] ?? null,
                'labels' => $params['labels'] ?? null,
            ]));

        return $response->json();
    }

    private function addComment(?string $token, array $params): array
    {
        $response = Http::withToken((string) $token)
            ->post(self::API_BASE."/repos/{$params['owner']}/{$params['repo']}/issues/{$params['issue_number']}/comments", [
                'body' => $params['body'],
            ]);

        return $response->json();
    }
}
