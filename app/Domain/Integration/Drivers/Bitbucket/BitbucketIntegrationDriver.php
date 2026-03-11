<?php

namespace App\Domain\Integration\Drivers\Bitbucket;

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
 * Bitbucket integration driver.
 *
 * Atlassian Bitbucket Cloud. Uses App Password (Basic auth).
 * Signature: x-hub-signature header — sha256=HMAC-SHA256 (optional, permissive if absent).
 * Implements SubscribableConnectorInterface for programmatic webhook registration.
 */
class BitbucketIntegrationDriver implements IntegrationDriverInterface, SubscribableConnectorInterface
{
    private const API_BASE = 'https://api.bitbucket.org/2.0';

    public function key(): string
    {
        return 'bitbucket';
    }

    public function label(): string
    {
        return 'Bitbucket';
    }

    public function description(): string
    {
        return 'Receive Bitbucket push, pull request, and pipeline events. Works with Bitbucket Cloud.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'workspace' => ['type' => 'string', 'required' => true, 'label' => 'Workspace',
                'hint' => 'Your Bitbucket workspace slug (from the URL)'],
            'username' => ['type' => 'string', 'required' => true, 'label' => 'Username'],
            'app_password' => ['type' => 'password', 'required' => true, 'label' => 'App Password',
                'hint' => 'Account Settings → App passwords → Create with repository webhooks scope'],
        ];
    }

    private function withAuth(Integration|array $source): \Illuminate\Http\Client\PendingRequest
    {
        [$user, $pass] = $source instanceof Integration
            ? [$source->getCredentialSecret('username'), $source->getCredentialSecret('app_password')]
            : [$source['username'] ?? '', $source['app_password'] ?? ''];

        return Http::withBasicAuth((string) $user, (string) $pass)->timeout(15);
    }

    public function validateCredentials(array $credentials): bool
    {
        if (empty($credentials['username']) || empty($credentials['app_password'])) {
            return false;
        }

        try {
            return Http::withBasicAuth($credentials['username'], $credentials['app_password'])
                ->timeout(10)
                ->get(self::API_BASE.'/user')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $start = microtime(true);
        try {
            $response = $this->withAuth($integration)->get(self::API_BASE.'/user');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('error.message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('push', 'Push', 'Code was pushed to a Bitbucket repository.'),
            new TriggerDefinition('pull_request_created', 'Pull Request Created', 'A pull request was opened.'),
            new TriggerDefinition('pull_request_merged', 'Pull Request Merged', 'A pull request was merged.'),
            new TriggerDefinition('issue_created', 'Issue Created', 'A new issue was filed.'),
            new TriggerDefinition('pipeline_failed', 'Pipeline Failed', 'A CI pipeline failed.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_issue', 'Create Issue', 'Create an issue on a Bitbucket repository.', [
                'repo_slug' => ['type' => 'string', 'required' => true, 'label' => 'Repository slug'],
                'title' => ['type' => 'string', 'required' => true, 'label' => 'Issue title'],
                'content' => ['type' => 'string', 'required' => false, 'label' => 'Issue description'],
                'priority' => ['type' => 'string', 'required' => false, 'label' => 'Priority: trivial|minor|major|critical|blocker'],
            ]),
            new ActionDefinition('add_comment', 'Add Comment', 'Add a comment to a pull request or issue.', [
                'repo_slug' => ['type' => 'string', 'required' => true, 'label' => 'Repository slug'],
                'pr_id' => ['type' => 'string', 'required' => true, 'label' => 'Pull request ID'],
                'content' => ['type' => 'string', 'required' => true, 'label' => 'Comment text'],
            ]),
            new ActionDefinition('approve_pull_request', 'Approve Pull Request', 'Approve a pull request.', [
                'repo_slug' => ['type' => 'string', 'required' => true, 'label' => 'Repository slug'],
                'pr_id' => ['type' => 'string', 'required' => true, 'label' => 'Pull request ID'],
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
     * Bitbucket signature: x-hub-signature header — sha256=HMAC-SHA256 (optional).
     * Returns true if the header is absent (permissive), false on bad signature.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $sig = $headers['x-hub-signature'] ?? '';

        if ($sig === '') {
            return true; // Bitbucket secret is optional; absent means no signing configured
        }

        if (! str_starts_with($sig, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $sig);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $event = $headers['x-event-key'] ?? 'repo:push';
        $repoSlug = $payload['repository']['full_name'] ?? 'unknown';
        $eventId = $payload['pullrequest']['id']
            ?? $payload['issue']['id']
            ?? Str::substr(hash('sha256', json_encode($payload)), 0, 16);

        $trigger = match ($event) {
            'repo:push' => 'push',
            'pullrequest:created' => 'pull_request_created',
            'pullrequest:fulfilled' => 'pull_request_merged',
            'issue:created' => 'issue_created',
            default => str_replace(':', '_', $event),
        };

        return [
            [
                'source_type' => 'bitbucket',
                'source_id' => "bitbucket:{$repoSlug}:{$eventId}",
                'payload' => $payload,
                'tags' => ['bitbucket', $trigger],
            ],
        ];
    }

    // SubscribableConnectorInterface

    public function registerWebhook(Integration $integration, array $filterConfig, string $callbackUrl): WebhookRegistrationDTO
    {
        $workspace = $integration->getCredentialSecret('workspace') ?? $integration->config['workspace'] ?? '';
        $repoSlug = $filterConfig['repo_slug'] ?? '';
        $events = $filterConfig['events'] ?? ['repo:push', 'pullrequest:created', 'pullrequest:fulfilled'];
        $secret = Str::random(40);

        $response = $this->withAuth($integration)
            ->post(self::API_BASE."/repositories/{$workspace}/{$repoSlug}/hooks", [
                'description' => 'FleetQ Integration',
                'url' => $callbackUrl,
                'active' => true,
                'secret' => $secret,
                'events' => $events,
            ]);

        $response->throw();

        return new WebhookRegistrationDTO(
            webhookId: $response->json('uid'),
            webhookSecret: $secret,
        );
    }

    public function deregisterWebhook(Integration $integration, string $webhookId, array $filterConfig): void
    {
        $workspace = $integration->getCredentialSecret('workspace') ?? $integration->config['workspace'] ?? '';
        $repoSlug = $filterConfig['repo_slug'] ?? '';

        $this->withAuth($integration)
            ->delete(self::API_BASE."/repositories/{$workspace}/{$repoSlug}/hooks/{$webhookId}");
    }

    public function verifySubscriptionSignature(string $rawBody, array $headers, string $webhookSecret): bool
    {
        return $this->verifyWebhookSignature($rawBody, $headers, $webhookSecret);
    }

    public function mapPayloadToSignalDTO(array $payload, array $headers, array $filterConfig): ?SignalDTO
    {
        $event = $headers['x-event-key'] ?? 'repo:push';
        $repoSlug = $payload['repository']['full_name'] ?? 'unknown';
        $eventId = $payload['pullrequest']['id']
            ?? $payload['issue']['id']
            ?? Str::substr(hash('sha256', json_encode($payload)), 0, 16);
        $trigger = str_replace(':', '_', $event);

        return new SignalDTO(
            sourceIdentifier: "bitbucket:{$repoSlug}:{$eventId}",
            sourceNativeId: "bitbucket.{$event}.{$eventId}",
            payload: $payload,
            tags: ['bitbucket', $trigger],
        );
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $workspace = $integration->getCredentialSecret('workspace') ?? $integration->config['workspace'] ?? '';
        $repoSlug = $params['repo_slug'] ?? '';
        $http = $this->withAuth($integration);

        return match ($action) {
            'create_issue' => $http->post(self::API_BASE."/repositories/{$workspace}/{$repoSlug}/issues", [
                'title' => $params['title'],
                'content' => ['raw' => $params['content'] ?? ''],
                'priority' => $params['priority'] ?? 'major',
            ])->json(),

            'add_comment' => $http->post(
                self::API_BASE."/repositories/{$workspace}/{$repoSlug}/pullrequests/{$params['pr_id']}/comments",
                ['content' => ['raw' => $params['content']]]
            )->json(),

            'approve_pull_request' => $http->post(
                self::API_BASE."/repositories/{$workspace}/{$repoSlug}/pullrequests/{$params['pr_id']}/approve"
            )->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
