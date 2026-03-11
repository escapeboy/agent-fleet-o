<?php

namespace App\Domain\Integration\Drivers\Jira;

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

/**
 * Jira Cloud integration driver — OAuth 2.0 (3LO).
 *
 * Auth: Atlassian OAuth2 via https://auth.atlassian.com/authorize.
 * cloudId is resolved from the accessible-resources endpoint after OAuth
 * and stored in the credential's secret_data['cloud_id'].
 *
 * Webhooks: Jira Cloud REST-registered webhooks (v3) expire after ~30 days.
 * No HMAC signature — security is the opaque subscription UUID in the callback URL.
 */
class JiraIntegrationDriver implements IntegrationDriverInterface, SubscribableConnectorInterface
{
    private const ATLASSIAN_API = 'https://api.atlassian.com';

    public function key(): string
    {
        return 'jira';
    }

    public function label(): string
    {
        return 'Jira';
    }

    public function description(): string
    {
        return 'Create and track Jira issues, add comments, transition tickets, and trigger on new issue activity.';
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
        $token = $credentials['access_token'] ?? null;
        $cloudId = $credentials['cloud_id'] ?? null;

        if (! $token || ! $cloudId) {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get(self::ATLASSIAN_API."/ex/jira/{$cloudId}/rest/api/3/myself");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $this->resolveToken($integration);
        $cloudId = $this->resolveCloudId($integration);

        if (! $token || ! $cloudId) {
            return HealthResult::fail('No access token or cloudId configured. Reconnect via OAuth.');
        }

        $start = microtime(true);
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get(self::ATLASSIAN_API."/ex/jira/{$cloudId}/rest/api/3/myself");
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $displayName = $response->json('displayName', 'unknown');

                return HealthResult::ok($latency, "Connected as {$displayName}");
            }

            return HealthResult::fail($response->json('message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('issue_created', 'Issue Created', 'A new Jira issue was created.'),
            new TriggerDefinition('issue_updated', 'Issue Updated', 'An existing Jira issue was updated.'),
            new TriggerDefinition('issue_transitioned', 'Issue Transitioned', 'A Jira issue moved to a new status.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_issue', 'Create Issue', 'Create a new Jira issue.', [
                'project_key' => ['type' => 'string', 'required' => true,  'label' => 'Project key (e.g. ENG)'],
                'summary' => ['type' => 'string', 'required' => true,  'label' => 'Issue summary/title'],
                'description' => ['type' => 'string', 'required' => false, 'label' => 'Issue description'],
                'issue_type' => ['type' => 'string', 'required' => false, 'label' => 'Issue type (Bug, Story, Task...)'],
                'priority' => ['type' => 'string', 'required' => false, 'label' => 'Priority (Highest, High, Medium, Low)'],
            ]),
            new ActionDefinition('update_issue', 'Update Issue', 'Update fields on an existing Jira issue.', [
                'issue_key' => ['type' => 'string', 'required' => true, 'label' => 'Issue key (e.g. ENG-42)'],
                'fields' => ['type' => 'array',  'required' => true, 'label' => 'Fields map to update'],
            ]),
            new ActionDefinition('transition_issue', 'Transition Issue', 'Move a Jira issue to a new status.', [
                'issue_key' => ['type' => 'string', 'required' => true, 'label' => 'Issue key'],
                'transition_id' => ['type' => 'string', 'required' => true, 'label' => 'Transition ID'],
            ]),
            new ActionDefinition('add_comment', 'Add Comment', 'Add a comment to a Jira issue.', [
                'issue_key' => ['type' => 'string', 'required' => true, 'label' => 'Issue key'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Comment body (plain text)'],
            ]),
            new ActionDefinition('assign_issue', 'Assign Issue', 'Assign a Jira issue to a team member.', [
                'issue_key' => ['type' => 'string', 'required' => true, 'label' => 'Issue key'],
                'account_id' => ['type' => 'string', 'required' => true, 'label' => 'Assignee account ID'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 0; // Webhook-driven via SubscribableConnectorInterface
    }

    public function poll(Integration $integration): array
    {
        return []; // Replaced by webhook subscriptions
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        // Legacy per-team webhook path — not used by subscription webhooks.
        return true;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $this->resolveToken($integration);
        $cloudId = $this->resolveCloudId($integration);
        abort_unless($token && $cloudId, 422, 'Jira OAuth credentials not configured.');

        $base = self::ATLASSIAN_API."/ex/jira/{$cloudId}/rest/api/3";

        return match ($action) {
            'create_issue' => Http::withToken($token)->timeout(15)
                ->post("{$base}/issue", [
                    'fields' => array_filter([
                        'project' => ['key' => $params['project_key']],
                        'summary' => $params['summary'],
                        'description' => isset($params['description']) ? [
                            'type' => 'doc', 'version' => 1,
                            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $params['description']]]]],
                        ] : null,
                        'issuetype' => ['name' => $params['issue_type'] ?? 'Task'],
                        'priority' => isset($params['priority']) ? ['name' => $params['priority']] : null,
                    ]),
                ])->json(),

            'update_issue' => Http::withToken($token)->timeout(15)
                ->put("{$base}/issue/{$params['issue_key']}", ['fields' => $params['fields']])->json(),

            'transition_issue' => Http::withToken($token)->timeout(15)
                ->post("{$base}/issue/{$params['issue_key']}/transitions", [
                    'transition' => ['id' => $params['transition_id']],
                ])->json(),

            'add_comment' => Http::withToken($token)->timeout(15)
                ->post("{$base}/issue/{$params['issue_key']}/comment", [
                    'body' => [
                        'type' => 'doc', 'version' => 1,
                        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $params['body']]]]],
                    ],
                ])->json(),

            'assign_issue' => Http::withToken($token)->timeout(15)
                ->put("{$base}/issue/{$params['issue_key']}/assignee", [
                    'accountId' => $params['account_id'],
                ])->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    // -----------------------------------------------------------------------
    // SubscribableConnectorInterface
    // -----------------------------------------------------------------------

    /**
     * Register a dynamic Jira Cloud webhook.
     *
     * Jira REST-registered webhooks expire after ~30 days and are scoped to
     * the OAuth app (not shared with other apps). No HMAC signing is available —
     * security is the opaque subscription UUID in the callback URL.
     *
     * Docs: POST /rest/api/3/webhook
     */
    public function registerWebhook(Integration $integration, array $filterConfig, string $callbackUrl): WebhookRegistrationDTO
    {
        $token = $this->resolveToken($integration);
        $cloudId = $this->resolveCloudId($integration);

        if (! $token || ! $cloudId) {
            throw new \RuntimeException('Jira integration has no access token or cloudId. Reconnect via OAuth.');
        }

        $projectKey = $filterConfig['project_key'] ?? null;
        $jqlFilter = $projectKey ? "project = \"{$projectKey}\"" : '';

        $events = $filterConfig['webhook_events'] ?? [
            'jira:issue_created',
            'jira:issue_updated',
            'comment_created',
            'comment_updated',
        ];

        $webhook = array_filter([
            'jqlFilter' => $jqlFilter ?: null,
            'events' => $events,
        ]);

        $response = Http::withToken($token)
            ->timeout(20)
            ->post(self::ATLASSIAN_API."/ex/jira/{$cloudId}/rest/api/3/webhook", [
                'url' => $callbackUrl,
                'webhooks' => [$webhook],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Jira webhook registration failed: '.$response->body());
        }

        /** @var array<int, array{createdWebhookId?: int, errors?: list<string>}> $results */
        $results = $response->json('webhookRegistrationResult') ?? [];

        if (empty($results[0]['createdWebhookId'])) {
            $errors = implode(', ', $results[0]['errors'] ?? ['Unknown error']);
            throw new \RuntimeException("Jira webhook registration failed: {$errors}");
        }

        $webhookId = (string) $results[0]['createdWebhookId'];

        // Jira webhooks expire in ~30 days. Schedule a refresh before expiry.
        $expiresAt = new \DateTimeImmutable('+29 days');

        return new WebhookRegistrationDTO(
            webhookId: $webhookId,
            webhookSecret: null, // Jira does not sign webhook payloads
            expiresAt: $expiresAt,
        );
    }

    /**
     * Delete a dynamic Jira Cloud webhook.
     *
     * Docs: DELETE /rest/api/3/webhook  (body: {"webhookIds": [id]})
     */
    public function deregisterWebhook(Integration $integration, string $webhookId, array $filterConfig): void
    {
        $token = $this->resolveToken($integration);
        $cloudId = $this->resolveCloudId($integration);

        if (! $token || ! $cloudId) {
            return;
        }

        Http::withToken($token)
            ->timeout(15)
            ->delete(self::ATLASSIAN_API."/ex/jira/{$cloudId}/rest/api/3/webhook", [
                'webhookIds' => [(int) $webhookId],
            ]);
    }

    /**
     * Jira Cloud REST-registered webhooks are not HMAC-signed.
     * The subscription UUID embedded in the callback URL serves as the shared secret.
     */
    public function verifySubscriptionSignature(string $rawBody, array $headers, string $webhookSecret): bool
    {
        return true;
    }

    /**
     * Map a Jira Cloud webhook payload to a normalized SignalDTO.
     *
     * Supported events: jira:issue_created, jira:issue_updated, jira:issue_deleted,
     * comment_created, comment_updated.
     */
    public function mapPayloadToSignalDTO(array $payload, array $headers, array $filterConfig): ?SignalDTO
    {
        $event = $payload['webhookEvent'] ?? '';

        $allowedEvents = [
            'jira:issue_created',
            'jira:issue_updated',
            'jira:issue_deleted',
            'comment_created',
            'comment_updated',
        ];

        if (! in_array($event, $allowedEvents, true)) {
            return null;
        }

        // Filter by configured project key
        $filterProject = $filterConfig['project_key'] ?? null;
        $issueProjectKey = $payload['issue']['fields']['project']['key'] ?? null;
        if ($filterProject && $issueProjectKey && strtoupper($filterProject) !== strtoupper($issueProjectKey)) {
            return null;
        }

        $issue = $payload['issue'] ?? [];
        $issueKey = $issue['key'] ?? null;
        $fields = $issue['fields'] ?? [];

        $labels = $fields['labels'] ?? [];
        $tags = array_values(array_unique(
            array_merge(['jira', str_replace('jira:', '', $event)], $labels),
        ));

        return new SignalDTO(
            sourceIdentifier: (string) ($issueKey ?? uniqid('jira_', true)),
            sourceNativeId: $issueKey ? "jira.{$event}.{$issueKey}" : null,
            payload: array_merge($payload, ['jira_event' => $event]),
            tags: $tags,
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function resolveToken(Integration $integration): ?string
    {
        return $integration->getCredentialSecret('access_token');
    }

    private function resolveCloudId(Integration $integration): ?string
    {
        return $integration->getCredentialSecret('cloud_id');
    }
}
<?php

namespace App\Domain\Integration\Drivers\Jira;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Jira Cloud integration driver.
 *
 * Auth: API Token + email (BasicAuth). instance_url is unique per org.
 * Phase 1: polling only (JQL-based). Jira Cloud webhooks require admin + public URL.
 *
 * @deprecated JiraConnector (signal-only poller) — use this driver for new integrations.
 */
class JiraIntegrationDriver implements IntegrationDriverInterface
{
    public function key(): string
    {
        return 'jira';
    }

    public function label(): string
    {
        return 'Jira';
    }

    public function description(): string
    {
        return 'Create and track Jira issues, add comments, transition tickets, and trigger on new issue activity.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'email' => ['type' => 'email',    'required' => true, 'label' => 'Jira Account Email'],
            'api_token' => ['type' => 'password', 'required' => true, 'label' => 'API Token',
                'hint' => 'Generate at id.atlassian.net/manage-profile/security/api-tokens'],
            'instance_url' => ['type' => 'url',      'required' => true, 'label' => 'Jira Instance URL',
                'hint' => 'e.g. https://myorg.atlassian.net'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $email = $credentials['email'] ?? null;
        $token = $credentials['api_token'] ?? null;
        $instanceUrl = $credentials['instance_url'] ?? null;

        if (! $email || ! $token || ! $instanceUrl) {
            return false;
        }

        try {
            $response = Http::withBasicAuth($email, $token)
                ->timeout(10)
                ->get(rtrim($instanceUrl, '/').'/rest/api/3/myself');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        [$email, $token, $instanceUrl] = $this->credentials($integration);

        if (! $email || ! $token || ! $instanceUrl) {
            return HealthResult::fail('Email, API token, or instance URL not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withBasicAuth($email, $token)
                ->timeout(10)
                ->get("{$instanceUrl}/rest/api/3/myself");
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $displayName = $response->json('displayName', $email);

                return HealthResult::ok($latency, "Connected as {$displayName}");
            }

            return HealthResult::fail($response->json('message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('issue_created', 'Issue Created', 'A new Jira issue was created in the configured project.'),
            new TriggerDefinition('issue_updated', 'Issue Updated', 'An existing Jira issue was updated.'),
            new TriggerDefinition('issue_transitioned', 'Issue Transitioned', 'A Jira issue moved to a new status.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_issue', 'Create Issue', 'Create a new Jira issue.', [
                'project_key' => ['type' => 'string', 'required' => true,  'label' => 'Project key (e.g. ENG)'],
                'summary' => ['type' => 'string', 'required' => true,  'label' => 'Issue summary/title'],
                'description' => ['type' => 'string', 'required' => false, 'label' => 'Issue description'],
                'issue_type' => ['type' => 'string', 'required' => false, 'label' => 'Issue type (Bug, Story, Task...)'],
                'priority' => ['type' => 'string', 'required' => false, 'label' => 'Priority (Highest, High, Medium, Low)'],
            ]),
            new ActionDefinition('update_issue', 'Update Issue', 'Update fields on an existing Jira issue.', [
                'issue_key' => ['type' => 'string', 'required' => true, 'label' => 'Issue key (e.g. ENG-42)'],
                'fields' => ['type' => 'array',  'required' => true, 'label' => 'Fields map to update'],
            ]),
            new ActionDefinition('transition_issue', 'Transition Issue', 'Move a Jira issue to a new status.', [
                'issue_key' => ['type' => 'string', 'required' => true, 'label' => 'Issue key'],
                'transition_id' => ['type' => 'string', 'required' => true, 'label' => 'Transition ID (from Get Transitions)'],
            ]),
            new ActionDefinition('add_comment', 'Add Comment', 'Add a comment to a Jira issue.', [
                'issue_key' => ['type' => 'string', 'required' => true, 'label' => 'Issue key'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Comment body (plain text)'],
            ]),
            new ActionDefinition('assign_issue', 'Assign Issue', 'Assign a Jira issue to a team member.', [
                'issue_key' => ['type' => 'string', 'required' => true, 'label' => 'Issue key'],
                'account_id' => ['type' => 'string', 'required' => true, 'label' => 'Assignee account ID'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 120;
    }

    public function poll(Integration $integration): array
    {
        [$email, $token, $instanceUrl] = $this->credentials($integration);
        if (! $email || ! $token || ! $instanceUrl) {
            return [];
        }

        $projectKey = $integration->config['project_key'] ?? null;
        $lastCreated = $integration->config['last_created'] ?? now()->subMinutes(3)->format('Y-m-d H:i');
        $jql = $projectKey
            ? "project = \"{$projectKey}\" AND created >= \"{$lastCreated}\" ORDER BY created ASC"
            : "created >= \"{$lastCreated}\" ORDER BY created ASC";

        try {
            $response = Http::withBasicAuth($email, $token)->timeout(15)
                ->get("{$instanceUrl}/rest/api/3/search", [
                    'jql' => $jql,
                    'maxResults' => 50,
                    'fields' => 'summary,status,priority,assignee,reporter,created,updated',
                ]);

            if (! $response->successful()) {
                return [];
            }

            $issues = $response->json('issues') ?? [];
            $signals = [];

            foreach ($issues as $issue) {
                $signals[] = [
                    'source_type' => 'jira',
                    'source_id' => 'jira:'.$issue['id'],
                    'payload' => $issue,
                    'tags' => ['jira', 'issue_created'],
                ];
            }

            $integration->update(['config' => array_merge($integration->config ?? [], [
                'last_created' => now()->format('Y-m-d H:i'),
            ])]);

            return $signals;
        } catch (\Throwable) {
            return [];
        }
    }

    public function supportsWebhooks(): bool
    {
        return false; // Phase 1: polling only
    }

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return true;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        [$email, $token, $instanceUrl] = $this->credentials($integration);
        abort_unless($email && $token && $instanceUrl, 422, 'Jira credentials not configured.');

        return match ($action) {
            'create_issue' => Http::withBasicAuth($email, $token)->timeout(15)
                ->post("{$instanceUrl}/rest/api/3/issue", [
                    'fields' => array_filter([
                        'project' => ['key' => $params['project_key']],
                        'summary' => $params['summary'],
                        'description' => isset($params['description']) ? [
                            'type' => 'doc',
                            'version' => 1,
                            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $params['description']]]]],
                        ] : null,
                        'issuetype' => ['name' => $params['issue_type'] ?? 'Task'],
                        'priority' => isset($params['priority']) ? ['name' => $params['priority']] : null,
                    ]),
                ])->json(),

            'update_issue' => Http::withBasicAuth($email, $token)->timeout(15)
                ->put("{$instanceUrl}/rest/api/3/issue/{$params['issue_key']}", [
                    'fields' => $params['fields'],
                ])->json(),

            'transition_issue' => Http::withBasicAuth($email, $token)->timeout(15)
                ->post("{$instanceUrl}/rest/api/3/issue/{$params['issue_key']}/transitions", [
                    'transition' => ['id' => $params['transition_id']],
                ])->json(),

            'add_comment' => Http::withBasicAuth($email, $token)->timeout(15)
                ->post("{$instanceUrl}/rest/api/3/issue/{$params['issue_key']}/comment", [
                    'body' => [
                        'type' => 'doc',
                        'version' => 1,
                        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $params['body']]]]],
                    ],
                ])->json(),

            'assign_issue' => Http::withBasicAuth($email, $token)->timeout(15)
                ->put("{$instanceUrl}/rest/api/3/issue/{$params['issue_key']}/assignee", [
                    'accountId' => $params['account_id'],
                ])->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    /** @return array{string|null, string|null, string|null} [email, api_token, instance_url] */
    private function credentials(Integration $integration): array
    {
        $creds = $integration->credential?->secret_data ?? [];

        return [
            $creds['email'] ?? $integration->getCredentialSecret('email'),
            $creds['api_token'] ?? $integration->getCredentialSecret('api_token'),
            rtrim($creds['instance_url'] ?? $integration->config['instance_url'] ?? '', '/') ?: null,
        ];
    }
}
>>>>>>> origin/feat/imap-complete-integration
