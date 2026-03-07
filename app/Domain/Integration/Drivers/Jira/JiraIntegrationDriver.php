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
            'email'        => ['type' => 'email',    'required' => true, 'label' => 'Jira Account Email'],
            'api_token'    => ['type' => 'password', 'required' => true, 'label' => 'API Token',
                                'hint' => 'Generate at id.atlassian.net/manage-profile/security/api-tokens'],
            'instance_url' => ['type' => 'url',      'required' => true, 'label' => 'Jira Instance URL',
                                'hint' => 'e.g. https://myorg.atlassian.net'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $email       = $credentials['email'] ?? null;
        $token       = $credentials['api_token'] ?? null;
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
            new TriggerDefinition('issue_created',     'Issue Created',     'A new Jira issue was created in the configured project.'),
            new TriggerDefinition('issue_updated',     'Issue Updated',     'An existing Jira issue was updated.'),
            new TriggerDefinition('issue_transitioned','Issue Transitioned','A Jira issue moved to a new status.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_issue', 'Create Issue', 'Create a new Jira issue.', [
                'project_key' => ['type' => 'string', 'required' => true,  'label' => 'Project key (e.g. ENG)'],
                'summary'     => ['type' => 'string', 'required' => true,  'label' => 'Issue summary/title'],
                'description' => ['type' => 'string', 'required' => false, 'label' => 'Issue description'],
                'issue_type'  => ['type' => 'string', 'required' => false, 'label' => 'Issue type (Bug, Story, Task...)'],
                'priority'    => ['type' => 'string', 'required' => false, 'label' => 'Priority (Highest, High, Medium, Low)'],
            ]),
            new ActionDefinition('update_issue', 'Update Issue', 'Update fields on an existing Jira issue.', [
                'issue_key' => ['type' => 'string', 'required' => true, 'label' => 'Issue key (e.g. ENG-42)'],
                'fields'    => ['type' => 'array',  'required' => true, 'label' => 'Fields map to update'],
            ]),
            new ActionDefinition('transition_issue', 'Transition Issue', 'Move a Jira issue to a new status.', [
                'issue_key'     => ['type' => 'string', 'required' => true, 'label' => 'Issue key'],
                'transition_id' => ['type' => 'string', 'required' => true, 'label' => 'Transition ID (from Get Transitions)'],
            ]),
            new ActionDefinition('add_comment', 'Add Comment', 'Add a comment to a Jira issue.', [
                'issue_key' => ['type' => 'string', 'required' => true, 'label' => 'Issue key'],
                'body'      => ['type' => 'string', 'required' => true, 'label' => 'Comment body (plain text)'],
            ]),
            new ActionDefinition('assign_issue', 'Assign Issue', 'Assign a Jira issue to a team member.', [
                'issue_key'  => ['type' => 'string', 'required' => true, 'label' => 'Issue key'],
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

        $projectKey  = $integration->config['project_key'] ?? null;
        $lastCreated = $integration->config['last_created'] ?? now()->subMinutes(3)->format('Y-m-d H:i');
        $jql         = $projectKey
            ? "project = \"{$projectKey}\" AND created >= \"{$lastCreated}\" ORDER BY created ASC"
            : "created >= \"{$lastCreated}\" ORDER BY created ASC";

        try {
            $response = Http::withBasicAuth($email, $token)->timeout(15)
                ->get("{$instanceUrl}/rest/api/3/search", [
                    'jql'        => $jql,
                    'maxResults' => 50,
                    'fields'     => 'summary,status,priority,assignee,reporter,created,updated',
                ]);

            if (! $response->successful()) {
                return [];
            }

            $issues  = $response->json('issues') ?? [];
            $signals = [];

            foreach ($issues as $issue) {
                $signals[] = [
                    'source_type' => 'jira',
                    'source_id'   => 'jira:'.$issue['id'],
                    'payload'     => $issue,
                    'tags'        => ['jira', 'issue_created'],
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
                        'project'     => ['key' => $params['project_key']],
                        'summary'     => $params['summary'],
                        'description' => isset($params['description']) ? [
                            'type'    => 'doc',
                            'version' => 1,
                            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $params['description']]]]],
                        ] : null,
                        'issuetype'   => ['name' => $params['issue_type'] ?? 'Task'],
                        'priority'    => isset($params['priority']) ? ['name' => $params['priority']] : null,
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
                        'type'    => 'doc',
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
