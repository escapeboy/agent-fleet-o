<?php

namespace App\Domain\Integration\Drivers\Sentry;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Sentry integration driver.
 *
 * Uses Sentry Internal Integration auth tokens. Webhooks use sentry-hook-signature
 * (HMAC-SHA256, hex digest, no prefix). Organisation slug is required for all API calls.
 *
 * @deprecated SentryAlertConnector (signal-only) — use this driver for new integrations.
 */
class SentryIntegrationDriver implements IntegrationDriverInterface
{
    private const DEFAULT_BASE = 'https://sentry.io';

    public function key(): string
    {
        return 'sentry';
    }

    public function label(): string
    {
        return 'Sentry';
    }

    public function description(): string
    {
        return 'Receive Sentry error alerts, resolve issues, assign bugs, and add comments from agent workflows.';
    }

    public function authType(): AuthType
    {
        return AuthType::OAuth2;
    }

    public function credentialSchema(): array
    {
        return [];
    }

    private function apiBase(Integration|array $integration): string
    {
        $url = $integration instanceof Integration
            ? ($integration->getCredentialSecret('base_url') ?? '')
            : ($integration['base_url'] ?? '');

        $url = rtrim((string) $url, '/');

        return ($url !== '' ? $url : self::DEFAULT_BASE).'/api/0';
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? $credentials['auth_token'] ?? null;
        $orgSlug = $credentials['org_slug'] ?? null;

        if (! $token || ! $orgSlug) {
            return false;
        }

        try {
            $apiBase = $this->apiBase($credentials);
            $response = Http::withToken($token)
                ->timeout(10)
                ->get("{$apiBase}/organizations/{$orgSlug}/");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('auth_token');
        $orgSlug = $integration->config['org_slug']
            ?? $integration->getCredentialSecret('org_slug');

        if (! $token || ! $orgSlug) {
            return HealthResult::fail('Auth token or organisation slug not configured.');
        }

        $start = microtime(true);
        try {
            $apiBase = $this->apiBase($integration);
            $response = Http::withToken($token)
                ->timeout(10)
                ->get("{$apiBase}/organizations/{$orgSlug}/");
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $name = $response->json('name', $orgSlug);

                return HealthResult::ok($latency, "Connected to {$name}");
            }

            return HealthResult::fail($response->json('detail') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('issue_created', 'Issue Created', 'A new Sentry issue was detected.'),
            new TriggerDefinition('issue_resolved', 'Issue Resolved', 'A Sentry issue was marked as resolved.'),
            new TriggerDefinition('error_alert_triggered', 'Error Alert Triggered', 'A Sentry alert rule fired.'),
            new TriggerDefinition('comment_created', 'Comment Created', 'A comment was added to a Sentry issue.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('resolve_issue', 'Resolve Issue', 'Mark a Sentry issue as resolved.', [
                'issue_id' => ['type' => 'string', 'required' => true, 'label' => 'Issue ID'],
            ]),
            new ActionDefinition('assign_issue', 'Assign Issue', 'Assign a Sentry issue to a team member.', [
                'issue_id' => ['type' => 'string', 'required' => true, 'label' => 'Issue ID'],
                'assignee' => ['type' => 'string', 'required' => true, 'label' => 'Username or email'],
            ]),
            new ActionDefinition('create_note', 'Create Note', 'Add a comment to a Sentry issue.', [
                'issue_id' => ['type' => 'string', 'required' => true, 'label' => 'Issue ID'],
                'text' => ['type' => 'string', 'required' => true, 'label' => 'Comment text'],
            ]),
            new ActionDefinition('update_issue', 'Update Issue', 'Update issue status and priority.', [
                'issue_id' => ['type' => 'string', 'required' => true,  'label' => 'Issue ID'],
                'status' => ['type' => 'string', 'required' => false, 'label' => 'Status: resolved|ignored|unresolved'],
                'priority' => ['type' => 'string', 'required' => false, 'label' => 'Priority: critical|high|medium|low'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 120;
    }

    public function poll(Integration $integration): array
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('auth_token');
        $orgSlug = $integration->config['org_slug']
            ?? $integration->getCredentialSecret('org_slug');

        if (! $token || ! $orgSlug) {
            return [];
        }

        try {
            $apiBase = $this->apiBase($integration);

            // Only unresolved issues — resolved issues are closed work and must
            // not be re-ingested as fresh signals.
            $query = ['query' => 'is:unresolved', 'limit' => 25];

            // Scope to a single Sentry project when config['project_id'] is set
            // (numeric Sentry project id); otherwise polls the whole org.
            if (! empty($integration->config['project_id'])) {
                $query['project'] = $integration->config['project_id'];
            }

            $response = Http::withToken($token)
                ->timeout(15)
                ->get("{$apiBase}/organizations/{$orgSlug}/issues/", $query);

            if (! $response->successful()) {
                return [];
            }

            return array_map(function ($issue) use ($apiBase, $token) {
                // The issues-list endpoint returns no stacktrace. Attach the
                // latest event's in-app frames so downstream triage can name
                // concrete suspect files instead of guessing from the title.
                $issue['suspect_frames'] = $this->fetchSuspectFrames($apiBase, $token, (string) ($issue['id'] ?? ''));

                return [
                    'source_type' => 'sentry',
                    'source_id' => 'sentry:'.$issue['id'],
                    'payload' => $issue,
                    'tags' => ['sentry', 'issue', $issue['level'] ?? 'error'],
                ];
            }, $response->json() ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Fetch the latest event for an issue and return its in-app stacktrace
     * frames as compact "path:line in function()" strings (top 15). Never
     * throws — a frame-fetch failure must not abort ingestion of the issue.
     *
     * @return list<string>
     */
    private function fetchSuspectFrames(string $apiBase, string $token, string $issueId): array
    {
        if ($issueId === '') {
            return [];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(8)
                ->get("{$apiBase}/issues/{$issueId}/events/latest/");

            if (! $response->successful()) {
                return [];
            }

            $entries = $response->json('entries') ?? [];
            $frames = [];
            foreach ($entries as $entry) {
                if (($entry['type'] ?? null) !== 'exception') {
                    continue;
                }

                foreach ($entry['data']['values'] ?? [] as $value) {
                    foreach ($value['stacktrace']['frames'] ?? [] as $frame) {
                        $inApp = $frame['inApp'] ?? $frame['in_app'] ?? false;
                        if (! $inApp) {
                            continue;
                        }

                        $path = $frame['filename'] ?? $frame['absPath'] ?? $frame['abs_path'] ?? null;
                        if (! is_string($path) || $path === '') {
                            continue;
                        }

                        $line = $frame['lineNo'] ?? $frame['lineno'] ?? null;
                        $fn = $frame['function'] ?? null;
                        $frames[] = $path
                            .($line !== null ? ':'.$line : '')
                            .(is_string($fn) && $fn !== '' ? ' in '.$fn.'()' : '');
                    }
                }
            }

            return array_slice(array_values(array_unique($frames)), 0, 15);
        } catch (\Throwable) {
            return [];
        }
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * Sentry uses sentry-hook-signature: HMAC-SHA256(client_secret, rawBody) — raw hex, no prefix.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $signature = $headers['sentry-hook-signature'] ?? '';
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $action = $payload['action'] ?? 'unknown';
        $resource = $headers['sentry-hook-resource'] ?? 'unknown';

        $trigger = match ("{$resource}.{$action}") {
            'issue.created' => 'issue_created',
            'issue.resolved' => 'issue_resolved',
            'error.created' => 'error_alert_triggered',
            'comment.created' => 'comment_created',
            default => "{$resource}_{$action}",
        };

        $issueId = $payload['data']['issue']['id']
            ?? $payload['data']['event']['id']
            ?? uniqid('sentry_', true);

        return [
            [
                'source_type' => 'sentry',
                'source_id' => 'sentry:'.$issueId,
                'payload' => $payload,
                'tags' => ['sentry', $trigger],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('auth_token');
        $orgSlug = $integration->config['org_slug']
            ?? $integration->getCredentialSecret('org_slug');

        abort_unless($token && $orgSlug, 422, 'Sentry credentials not configured.');

        $apiBase = $this->apiBase($integration);

        return match ($action) {
            'resolve_issue' => Http::withToken($token)->timeout(15)
                ->put("{$apiBase}/issues/{$params['issue_id']}/", ['status' => 'resolved'])
                ->json(),

            'assign_issue' => Http::withToken($token)->timeout(15)
                ->put("{$apiBase}/issues/{$params['issue_id']}/", ['assignedTo' => $params['assignee']])
                ->json(),

            'create_note' => Http::withToken($token)->timeout(15)
                ->post("{$apiBase}/issues/{$params['issue_id']}/comments/", ['text' => $params['text']])
                ->json(),

            'update_issue' => Http::withToken($token)->timeout(15)
                ->put("{$apiBase}/issues/{$params['issue_id']}/", array_filter([
                    'status' => $params['status'] ?? null,
                    'priority' => $params['priority'] ?? null,
                ]))->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
